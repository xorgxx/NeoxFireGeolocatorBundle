<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\EventListener;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\BanManager;
use Neox\FireGeolocatorBundle\Service\Bridge\EventBridgeInterface;
use Neox\FireGeolocatorBundle\Service\ExclusionManager;
use Neox\FireGeolocatorBundle\Service\Filter\FilterChain;
use Neox\FireGeolocatorBundle\Service\GeoContextResolver;
use Neox\FireGeolocatorBundle\Service\Log\GeolocatorLoggerInterface;
use Neox\FireGeolocatorBundle\Service\Privacy\AnonymizationService;
use Neox\FireGeolocatorBundle\Service\ResponseFactory;
use Neox\FireGeolocatorBundle\Service\Security\RateLimiterGuard;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
class GeolocatorRequestListener
{
    private const ATTR_CFG       = 'geolocator_config';
    private const ATTR_SIMULATE  = 'geolocator_simulate';
    private const ATTR_EXCLUDED  = 'geolocator_excluded';
    private const ATTR_CONTEXT   = 'geolocator_context';
    private const ATTR_AUTH      = 'geolocator_auth';
    private const QUERY_SIMULATE = 'geo_simulate';

    public function __construct(
        private array $config,
        private GeoContextResolver $resolver,
        private ResponseFactory $responseFactory,
        private BanManager $ban,
        private ExclusionManager $exclusions,
        private GeolocatorLoggerInterface $logger,
        private EventBridgeInterface $bridge,
        private RateLimiterGuard $limiterGuard,
        private ?FilterChain $appFilters = null, // custom filter chain (optional)
        private ?AnonymizationService $privacy = null,
        private ?\Neox\FireGeolocatorBundle\Service\Context\GeoContextHydratorInterface $ctxHydrator = null,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        $cfg = $request->attributes->get(self::ATTR_CFG);
        if (!$cfg instanceof ResolvedGeoApiConfigDTO) {
            return;
        }

        if (!$event->isMainRequest() || !($cfg->enabled ?? true)) {
            return;
        }

        $controller = $request->attributes->get('_controller');
        if (!is_string($controller) || !str_contains($controller, '::')) {
            return;
        }

        // Effective simulate mode: allow per-request override via query parameter ?geo_simulate=1|0
        $simulate = $this->resolveSimulateMode($request, $cfg);
        $request->attributes->set(self::ATTR_SIMULATE, $simulate);

        // 1) exempt routes
        $routeName = $request->attributes->get('_route');
        $routes    = $cfg->getTrusted()['routes'] ?? [];
        $routes    = is_array($routes) ? $routes : [];
        if (is_string($routeName) && $routeName !== '' && $this->routeIsExempt($routeName, $routes)) {
            return;
        }

        // 2) Cache-first provider resolution to avoid api request again
        $sessionCached = $this->getSessionCachedContext($request, $cfg);
        if ($sessionCached !== null) {
            $ctxPayload = $sessionCached['ctx'] ?? null;
            $ip         = $request->getClientIp() ?: '0.0.0.0';
            if (is_array($ctxPayload)) {
                // Re-hydrate minimal DTO from sanitized payload for filters using hydrator (if available)
                $ctx = $this->ctxHydrator ? $this->ctxHydrator->hydrateFromSanitized($ip, $ctxPayload) : null;
            } else {
                $ctx = $ctxPayload; // already DTO or null
            }
        } else {
            $ctx = $this->resolver->resolve($request, $cfg);
            $ip  = $ctx?->ip ?? ($request->getClientIp() ?: '0.0.0.0');
            $this->storeSessionCachedContext($request, $cfg, $ctx, $ip);
        }

        // 3) Exclusions (temporary bypass)
        $exKey = $this->computeExclusionKey($request, $cfg, $ip);
        if ($this->exclusions->isExcluded('key', $exKey)) {
            $request->attributes->set(self::ATTR_EXCLUDED, true);
            $this->logger->info('Request excluded by exclusion cache', [
                'key'          => $exKey,
                'ip_hash'      => $this->privacy?->anonymizeIp($ip),
                'algo_version' => $this->privacy?->getAlgoVersion(),
            ]);

            return; // bypass bans and rules
        }

        // 4) Rate limiter (complementary control)
        $rateKey = $this->privacy ? $this->privacy->buildRateKey($ip, $this->getSessionId($request)) : ('rate:' . ($ip ?: '0.0.0.0') . ':' . ($this->getSessionId($request) ?? ''));
        if (!$this->limiterGuard->allow($rateKey, 1)) {
            $this->logger->warning('Rate limit exceeded', ['ip_hash' => $this->privacy?->anonymizeIp($ip), 'algo_version' => $this->privacy?->getAlgoVersion()]);
            $auth = new AuthorizationDTO(false, 'Rate limit exceeded', 'throttle');
            if (!$simulate) {
                $event->setResponse($this->responseFactory->denied($this->resolveRedirectOnBan($cfg), $auth, null));
                $this->bridge->notify('geolocator.rate_limited', ['ip_hash' => $this->privacy?->anonymizeIp($ip), 'algo_version' => $this->privacy?->getAlgoVersion()]);

                return;
            } else {
                $this->bridge->notify('geolocator.rate_limited_simulated', ['ip_hash' => $this->privacy?->anonymizeIp($ip), 'algo_version' => $this->privacy?->getAlgoVersion()]);
                // continue processing in simulate mode
            }
        }

        // 5) Respect simulate mode (if simulate, do not block, just log)
        $banKey = $this->privacy ? $this->privacy->buildBanKey($ip, $this->getSessionId($request)) : ('ip-' . $ip);
        if ($this->ban->isBanned($banKey)) {
            $this->logger->warning('IP banned', ['ip_hash' => $this->privacy?->anonymizeIp($ip), 'algo_version' => $this->privacy?->getAlgoVersion()]);
            if (!$simulate) {
                $response = $this->responseFactory->banned($this->resolveRedirectOnBan($cfg), $ctx);
                $event->setResponse($response);
                $this->bridge->notify('geolocator.banned', ['ip_hash' => $this->privacy?->anonymizeIp($ip), 'algo_version' => $this->privacy?->getAlgoVersion()]);

                return;
            } else {
                $this->bridge->notify('geolocator.banned_simulated', ['ip_hash' => $this->privacy?->anonymizeIp($ip), 'algo_version' => $this->privacy?->getAlgoVersion()]);
            }
        }

        // 6) Delegate decision to FilterChain (core + custom)
        $decision = $this->appFilters?->decide($request, $ctx);
        $auth     = $decision ?? new AuthorizationDTO(true, null, null);

        // Provider error handling: only deny if no allowing decision was provided
        if (!$ctx && $cfg->blockOnError && ($decision === null || $auth->allowed === false)) {
            $auth = $auth->allowed === false ? $auth : new AuthorizationDTO(false, 'Provider error', 'provider');
            if ($simulate) {
                $this->logger->warning('Simulate: provider error ignored', ['ip_hash' => $this->privacy?->anonymizeIp($ip), 'algo_version' => $this->privacy?->getAlgoVersion()]);
            } else {
                $event->setResponse($this->responseFactory->denied($this->resolveRedirectOnBan($cfg), $auth, null));
                $this->bridge->notify('geolocator.denied', [
                    'ip_hash'      => $this->privacy?->anonymizeIp($ip),
                    'algo_version' => $this->privacy?->getAlgoVersion(),
                    'reason'       => $auth->reason,
                ]);

                return;
            }
        }

        if ($auth->allowed === false) {
            if (!$simulate) {
                $this->ban->increment($this->privacy ? $this->privacy->buildBanKey($ip, $this->getSessionId($request)) : ('ip-' . $ip));
                $this->logger->warning('Access denied by filters', [
                    'ip_hash'      => $this->privacy?->anonymizeIp($ip),
                    'algo_version' => $this->privacy?->getAlgoVersion(),
                    'reason'       => $auth->reason,
                ]);
                $event->setResponse($this->responseFactory->denied($this->resolveRedirectOnBan($cfg), $auth, $ctx));
                $this->bridge->notify('geolocator.denied', [
                    'ip_hash'      => $this->privacy?->anonymizeIp($ip),
                    'algo_version' => $this->privacy?->getAlgoVersion(),
                    'reason'       => $auth->reason,
                    'country'      => $ctx?->countryCode,
                ]);

                return;
            } else {
                $this->logger->info('Simulate: would deny', [
                    'ip_hash'      => $this->privacy?->anonymizeIp($ip),
                    'algo_version' => $this->privacy?->getAlgoVersion(),
                    'reason'       => $auth->reason,
                ]);
            }
        }

        $request->attributes->set(self::ATTR_CFG, $cfg);
        $request->attributes->set(self::ATTR_CONTEXT, $ctx);
        $request->attributes->set(self::ATTR_AUTH, $auth);

        $this->bridge->notify('geolocator.allowed', [
            'ip_hash'      => $this->privacy?->anonymizeIp($ip),
            'algo_version' => $this->privacy?->getAlgoVersion(),
            'country'      => $ctx?->countryCode,
            'simulate'     => $simulate,
            'allowed'      => $auth->allowed,
        ]);
    }

    private function resolveSimulateMode(Request $request, ResolvedGeoApiConfigDTO $cfg): bool
    {
        $simRaw = $request->query->get(self::QUERY_SIMULATE);
        if ($simRaw !== null) {
            $val = strtolower((string) $simRaw);
            if (in_array($val, [
                '1',
                'true',
                'yes',
                'on',
            ], true)) {
                return true;
            } elseif (in_array($val, [
                '0',
                'false',
                'no',
                'off',
            ], true)) {
                return false;
            } else {
                return (bool) $cfg->simulate;
            }
        }

        return (bool) $cfg->simulate;
    }

    private function routeIsExempt(string $routeName, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (!is_string($p) || $p === '') {
                continue;
            }
            if ($routeName === $p) {
                return true;
            }
            if ($this->patternMatch($p, $routeName)) {
                return true;
            }
        }

        return false;
    }

    private function patternMatch(string $pattern, string $subject): bool
    {
        // Support "*" and "?" wildcard patterns
        $escaped = preg_quote($pattern, '/');
        $regex   = '/^' . str_replace([
            '\\*',
            '\\?',
        ], [
            '.*',
            '.',
        ], $escaped) . '$/i';

        return (bool) preg_match($regex, $subject);
    }

    private function getSessionId(Request $request): ?string
    {
        try {
            if (!$request->hasSession()) {
                return null;
            }
            $session = $request->getSession();
            if (method_exists($session, 'isStarted') && $session->isStarted()) {
                $id = $session->getId();

                return is_string($id) && $id !== '' ? $id : null;
            }
            // Cookie-first without starting the session
            $rawName = method_exists($session, 'getName') ? $session->getName() : (function_exists('session_name') ? session_name() : null);
            $name    = is_string($rawName) && $rawName !== '' ? $rawName : 'PHPSESSID';
            $cookie  = $request->cookies->get($name);

            return is_string($cookie) && $cookie !== '' ? $cookie : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getSessionCachedContext(Request $request, object $cfg): ?array
    {
        try {
            $session = $request->hasSession() ? $request->getSession() : null;

            // 1) Try session-based key if session is available (without forcing start)
            $sessionPayload = null;
            $sessionKey     = null;
            if ($session) {
                $sessionKey = 'geo_ctx:session:' . ($this->getSessionId($request) ?? '');
                if ($this->getSessionId($request)) {
                    $sessionPayload = $session->get($sessionKey);
                    $this->logger->debug('[geo_ctx][read] session key', ['key' => $sessionKey]);
                }
            }

            // Helper closure to validate payload with TTL
            $isValid = function ($payload): bool {
                if (!is_array($payload) || !array_key_exists('ctx', $payload) || !array_key_exists('ip_hash', $payload)) {
                    return false;
                }
                $ts  = $payload['ts']  ?? null;
                $ttl = $payload['ttl'] ?? null;
                if ($ts !== null && $ttl !== null && is_numeric($ts) && is_numeric($ttl)) {
                    if (((int) $ts + (int) $ttl) < time()) {
                        return false; // expired
                    }
                }

                return true;
            };

            if ($isValid($sessionPayload)) {
                return $sessionPayload;
            }

            // 2) Fallback to IP key based on request->getClientIp() (never ctx->ip)
            $clientIp = $request->getClientIp() ?: '0.0.0.0';
            $algo     = $this->privacy?->getAlgoVersion()       ?? 'v1';
            $ipHash   = $this->privacy?->anonymizeIp($clientIp) ?? 'unknown';
            $ipKey    = sprintf('geo_ctx:ip:%s:%s', $algo, $ipHash);
            if ($session) {
                $this->logger->debug('[geo_ctx][read] ip key', ['key' => $ipKey]);
                $ipPayload = $session->get($ipKey);
                if ($isValid($ipPayload)) {
                    return $ipPayload;
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function storeSessionCachedContext(Request $request, object $cfg, mixed $ctx, string $ip): void
    {
        try {
            if (!$request->hasSession()) {
                return;
            }
            $session = $request->getSession();

            // Ensure session is started before set(); log diagnostic
            $started = method_exists($session, 'isStarted') && $session->isStarted();
            if (!$started && method_exists($session, 'start')) {
                $session->start();
                $started = true;
            }
            $this->logger->debug('[geo_ctx][write] session started?', ['started' => $started]);

            // Keys: always compute session key if session id available, and IP key from request->getClientIp()
            $sid        = $this->getSessionId($request);
            $sessionKey = $sid ? ('geo_ctx:session:' . $sid) : null;
            $clientIp   = $request->getClientIp() ?: '0.0.0.0';
            $algo       = $this->privacy?->getAlgoVersion()       ?? 'v1';
            $ipHash     = $this->privacy?->anonymizeIp($clientIp) ?? 'unknown';
            $ipKey      = sprintf('geo_ctx:ip:%s:%s', $algo, $ipHash);

            $ttl     = method_exists($cfg, 'getCacheTtl') ? $cfg->getCacheTtl() : ($cfg->cacheTtl ?? null);
            $san     = $this->privacy?->sanitizeContext($ctx ?? []) ?? ($ctx ?? []);
            $payload = [
                'ctx'          => $san,
                'ip_hash'      => $this->privacy?->anonymizeIp($ip),
                'algo_version' => $this->privacy?->getAlgoVersion(),
                'ts'           => time(),
                'ttl'          => $ttl,
            ];

            if ($sessionKey) {
                $this->logger->debug('[geo_ctx][write] session key', ['key' => $sessionKey]);
                $session->set($sessionKey, $payload);
            }
            $this->logger->debug('[geo_ctx][write] ip key', ['key' => $ipKey]);
            $session->set($ipKey, $payload);
        } catch (\Throwable) {
            // ignore caching errors
        }
    }

    // ----------------- Helpers anti-redondance -----------------

    private function isPrivateOrLoopbackIp(string $ip): bool
    {
        $ip = trim(strtolower($ip));
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }
        // IPv4 private ranges: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
        if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $ip)) {
            return true;
        }
        // IPv6 Unique Local Addresses fc00::/7 (fc00..fdff)
        if (str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd')) {
            return true;
        }

        return false;
    }

    private function resolveRedirectOnBan(object $cfg): ?string
    {
        $val = $this->config['redirect_on_ban'] ?? (method_exists($cfg, 'getRedirectOnBan') ? $cfg->getRedirectOnBan() : ($cfg->redirectOnBan ?? null));

        return is_string($val) ? $val : null;
    }

    private function computeExclusionKey(Request $request, object $cfg, string $ip): string
    {
        $exKey = method_exists($cfg, 'getExclusionKey') ? $cfg->getExclusionKey() : ($cfg->exclusionKey ?? null);
        if (is_string($exKey) && $exKey !== '') {
            return $exKey;
        }
        $strategy = method_exists($cfg, 'getExclusionsKeyStrategy') ? $cfg->getExclusionsKeyStrategy() : 'ip';
        if ($strategy === 'session') {
            $sid = $this->getSessionId($request);
            if ($sid) {
                return 'exclusion:v1:' . $sid;
            }
        }

        // default to ip-based exclusion key using anonymized hash if service is available
        return $this->privacy ? $this->privacy->buildExclusionKey($ip, $this->getSessionId($request)) : ('exclusion:v1:' . $ip);
    }

    private function getCacheKeyStrategy(object $cfg): string
    {
        return method_exists($cfg, 'getCacheKeyStrategy') ? $cfg->getCacheKeyStrategy() : ($cfg->cacheKeyStrategy ?? 'ip');
    }

    private function buildGeoCacheKey(Request $request, string $strategy, ?string $ip = null): string
    {
        if ($strategy === 'session') {
            $sid = $this->getSessionId($request);
            if ($sid) {
                return 'geo_ctx:session:' . $sid;
            }
        }
        $clientIp = $ip ?? ($request->getClientIp() ?: '0.0.0.0');
        // Avoid building IP-based cache keys for private/loopback addresses
        if ($this->isPrivateOrLoopbackIp($clientIp)) {
            $sid = $this->getSessionId($request);
            if ($sid) {
                return 'geo_ctx:session:' . $sid;
            }
            $algo = $this->privacy?->getAlgoVersion() ?? 'v1';

            return sprintf('geo_ctx:ip:%s:%s', $algo, 'unknown');
        }
        $hash = $this->privacy?->anonymizeIp($clientIp);
        $algo = $this->privacy?->getAlgoVersion() ?? 'v1';

        return sprintf('geo_ctx:ip:%s:%s', $algo, $hash ?? 'unknown');
    }
}

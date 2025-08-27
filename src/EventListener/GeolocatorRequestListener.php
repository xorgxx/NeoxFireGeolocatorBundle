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
            $ctx = $sessionCached['ctx'];
            $ip  = $sessionCached['ip'];
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
                'key' => $exKey,
                'ip'  => $ip,
            ]);

            return; // bypass bans and rules
        }

        // 4) Rate limiter (complementary control)
        $rateKey = $this->buildIpKey($ip);
        if (!$this->limiterGuard->allow($rateKey, 1)) {
            $this->logger->warning('Rate limit exceeded', ['ip' => $ip]);
            $auth = new AuthorizationDTO(false, 'Rate limit exceeded', 'throttle');
            if (!$simulate) {
                $event->setResponse($this->responseFactory->denied($this->resolveRedirectOnBan($cfg), $auth, null));
                $this->bridge->notify('geolocator.rate_limited', ['ip' => $ip]);

                return;
            } else {
                $this->bridge->notify('geolocator.rate_limited_simulated', ['ip' => $ip]);
                // continue processing in simulate mode
            }
        }

        // 5) Respect simulate mode (if simulate, do not block, just log)
        if ($this->ban->isBanned($this->buildIpKey($ip))) {
            $this->logger->warning('IP banned', ['ip' => $ip]);
            if (!$simulate) {
                $response = $this->responseFactory->banned($this->resolveRedirectOnBan($cfg), $ctx);
                $event->setResponse($response);
                $this->bridge->notify('geolocator.banned', ['ip' => $ip]);

                return;
            } else {
                $this->bridge->notify('geolocator.banned_simulated', ['ip' => $ip]);
            }
        }

        if (!$ctx && $cfg->blockOnError) {
            $auth = new AuthorizationDTO(false, 'Provider error', 'provider');
            if ($simulate) {
                $this->logger->warning('Simulate: provider error ignored', ['ip' => $ip]);
            } else {
                $event->setResponse($this->responseFactory->denied($this->resolveRedirectOnBan($cfg), $auth, null));
                $this->bridge->notify('geolocator.denied', [
                    'ip'     => $ip,
                    'reason' => $auth->reason,
                ]);

                return;
            }
        }

        // 6) Delegate decision to FilterChain (core + custom)
        $decision = $this->appFilters?->decide($request, $ctx);
        $auth     = $decision ?? new AuthorizationDTO(true, null, null);

        if ($auth->allowed === false) {
            if (!$simulate) {
                $this->ban->increment($this->buildIpKey($ip));
                $this->logger->warning('Access denied by filters', [
                    'ip'     => $ip,
                    'reason' => $auth->reason,
                ]);
                $event->setResponse($this->responseFactory->denied($this->resolveRedirectOnBan($cfg), $auth, $ctx));
                $this->bridge->notify('geolocator.denied', [
                    'ip'      => $ip,
                    'reason'  => $auth->reason,
                    'country' => $ctx?->countryCode,
                ]);

                return;
            } else {
                $this->logger->info('Simulate: would deny', [
                    'ip'     => $ip,
                    'reason' => $auth->reason,
                ]);
            }
        }

        $request->attributes->set(self::ATTR_CFG, $cfg);
        $request->attributes->set(self::ATTR_CONTEXT, $ctx);
        $request->attributes->set(self::ATTR_AUTH, $auth);

        $this->bridge->notify('geolocator.allowed', [
            'ip'       => $ip,
            'country'  => $ctx?->countryCode,
            'simulate' => $simulate,
            'allowed'  => $auth->allowed,
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
            if (!$request->hasSession()) {
                return null;
            }
            $session = $request->getSession();

            $keyStrategy = $this->getCacheKeyStrategy($cfg);
            $cacheKey    = $this->buildGeoCacheKey($request, $keyStrategy);
            $payload     = $session->get($cacheKey);
            if (!is_array($payload) || !array_key_exists('ctx', $payload) || !array_key_exists('ip', $payload)) {
                return null;
            }

            // Optionally validate that IP in cache matches current IP
            return $payload;
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

            $keyStrategy = $this->getCacheKeyStrategy($cfg);
            $cacheKey    = $this->buildGeoCacheKey($request, $keyStrategy, $ip);

            $ttl = method_exists($cfg, 'getCacheTtl') ? $cfg->getCacheTtl() : ($cfg->cacheTtl ?? null);
            $session->set($cacheKey, [
                'ctx' => $ctx,
                'ip'  => $ip,
                'ts'  => time(),
                'ttl' => $ttl,
            ]);
        } catch (\Throwable) {
            // ignore caching errors
        }
    }

    // ----------------- Helpers anti-redondance -----------------

    private function buildIpKey(string $ip): string
    {
        return 'ip-' . $ip;
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

            return $sid ? ('sess-' . $sid) : $this->buildIpKey($ip);
        }

        return $this->buildIpKey($ip);
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
                return 'geo_ctx_' . $sid;
            }
        }
        $clientIp = $ip ?? ($request->getClientIp() ?: '0.0.0.0');

        return 'geo_ctx_ip_' . $clientIp;
    }
}

<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\EventListener;

use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Config\GeoAttributeResolver;
use Neox\FireGeolocatorBundle\Service\Log\GeolocatorLoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Computes an "effective" configuration (global + controller attributes) per request,
 * and stores it in the Request and the Session under 'geolocator_config'.
 * This listener must run BEFORE the GeolocatorRequestListener (higher priority).
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 15)]
final class GeoConfigCollectListener
{
    private static bool $trustedProxyWarningEmitted = false;

    /**
     * @param array<string,mixed> $globalConfig injected from %geolocator.config%
     */
    public function __construct(
        private GeoAttributeResolver $attributeResolver,
        private GeolocatorLoggerInterface $logger,
        private array $globalConfig // injected from %geolocator.config%
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Warn once if forwarded headers present but no trusted proxies configured
        $this->warnIfUntrustedProxiesAndHeaders($request);

        // Avoid recomputing if already present on the Request
        if ($request->attributes->has('geolocator_config')) {
            return;
        }

        $controllerCallable = $request->attributes->get('_controller');

        $effective = $this->DTOHydrat($this->globalConfig);
        // exempt routes: skip internal/profiler routes and keep defaults, but still expose config
        $routeName = $request->attributes->get('_route');
        $routes    = $effective->getTrusted()['routes'] ?? [];
        $routes    = is_array($routes) ? $routes : [];
        if (is_string($routeName) && $routeName !== '' && $this->routeIsExempt($routeName, $routes)) {
            $request->attributes->set('geolocator_config', $effective);

            return;
        }

        try {
            $effective = $this->buildEffectiveArray($controllerCallable);
        } catch (\Throwable $e) {
            $this->logger->info('GeoConfigCollectListener failed; fallback to global config', [
                'error'   => $e->getMessage(),
                'channel' => 'geolocator',
            ]);
        }

        // Session: single source of truth
        //        $session = $request->getSession();
        //        if ($session) {
        //            $session->set('geolocator_config', $effective);
        //        }

        // Expose in the Request
        $request->attributes->set('geolocator_config', $effective);
        //        $this->exposeConfigToRequest($request, $effective);
    }

    /**
     * @throws \ReflectionException
     */
    private function buildEffectiveArray(mixed $controllerCallable): ResolvedGeoApiConfigDTO
    {
        $defaults = $this->defaults();

        if (!$controllerCallable) {
            return $this->DTOHydrat($defaults);
        }

        $parsed = $this->attributeResolver->parseController($controllerCallable);
        if ($parsed === null) {
            return $this->DTOHydrat($defaults);
        }

        [
            $class,
            $method
        ]         = $parsed;
        $resolved = $this->attributeResolver->resolveForController($class, $method);
        $attrCfg  = $resolved['config'] ?? null;

        if (!is_array($attrCfg)) {
            return $this->DTOHydrat($defaults);
        }

        // Apply attribute overrides to YAML keys
        $result = $defaults;

        // Generic loop to avoid redundancy for simple overrides
        $map = [
            // sourceKey              => [targetKey,         cast]
            'enabled'              => ['enabled',               'bool'],
            'simulate'             => ['simulate',              'bool'],
            'redirectOnBan'        => ['redirect_on_ban',       null],
            'providerFallbackMode' => ['provider_fallback_mode', 'bool'],
            'forceProvider'        => ['forceProvider',         'string'],
            'cacheTtl'             => ['cacheTtl',              'int'],
            'blockOnError'         => ['blockOnError',          'bool'],
            'exclusionKey'         => ['exclusionKey',          'string'],
        ];

        foreach ($map as $src => [$target, $cast]) {
            if (array_key_exists($src, $attrCfg) && $attrCfg[$src] !== null) {
                $val = $attrCfg[$src];
                switch ($cast) {
                    case 'bool':   $val = (bool) $val;
                        break;
                    case 'int':    $val = (int) $val;
                        break;
                    case 'string': $val = (string) $val;
                        break;
                    default: /* no cast */ break;
                }
                $result[$target] = $val;
            }
        }

        // ... existing code ...
        if (array_key_exists('cacheKeyStrategy', $attrCfg) && $attrCfg['cacheKeyStrategy'] !== null) {
            $strategy = in_array($attrCfg['cacheKeyStrategy'], [
                'ip',
                'session',
            ], true) ? $attrCfg['cacheKeyStrategy'] : 'ip';
            $result['cache'] ??= [];
            $result['cache']['key_strategy'] = $strategy;
        }
        if (!empty($attrCfg['excludeRoutes']) && is_array($attrCfg['excludeRoutes'])) {
            $result['excludeRoutes'] = array_values(array_unique(array_merge($result['excludeRoutes'] ?? [], $attrCfg['excludeRoutes'])));
        }

        // New dedicated attributes -> to filters.*.rules via the resolver (modularized)
        if (is_array($attrCfg['countries'] ?? null)) {
            $this->attributeResolver->ensureFilterRules($result, 'country', $attrCfg['countries']);
        }
        if (is_array($attrCfg['ips'] ?? null)) {
            $this->attributeResolver->ensureFilterRules($result, 'ip', $attrCfg['ips']);
        }
        if (is_array($attrCfg['crawlers'] ?? null)) {
            $cat = isset(($result['filters'] ?? [])['crawler_filter']) ? 'crawler_filter' : 'crawler';
            $this->attributeResolver->ensureFilterRules($result, $cat, $attrCfg['crawlers']);
        }

        // Deep merge of filters (union/replacement on "rules" by normalized key) via helpers
        if (!empty($attrCfg['filters']) && is_array($attrCfg['filters'])) {
            $result['filters'] ??= [];

            foreach ($attrCfg['filters'] as $cat => $f) {
                // Base existante de la catégorie
                $existing = isset($result['filters'][$cat]) && is_array($result['filters'][$cat]) ? $result['filters'][$cat] : [];

                // 1) Merge des autres clés que "rules" (écrasement simple)
                foreach ($f as $k => $v) {
                    if ($k === 'rules') {
                        continue;
                    }
                    $existing[$k] = $v;
                }

                // 2) Merge des "rules" par clé normalisée (ltrim '+/-')
                $curRules          = isset($existing['rules']) && is_array($existing['rules']) ? $existing['rules'] : [];
                $incomingRules     = isset($f['rules'])        && is_array($f['rules']) ? $f['rules'] : [];
                $existing['rules'] = $this->attributeResolver->mergeRules($curRules, $incomingRules);

                // Affecter la catégorie fusionnée
                $result['filters'][$cat] = $existing;
            }
        }

        // Override VPN settings from shorthand attributes if provided
        if (array_key_exists('vpnEnabled', $attrCfg) || array_key_exists('vpnDefaultBehavior', $attrCfg)) {
            $result['filters'] ??= [];
            $vpn = isset($result['filters']['vpn']) && is_array($result['filters']['vpn']) ? $result['filters']['vpn'] : [];
            if (array_key_exists('vpnEnabled', $attrCfg) && $attrCfg['vpnEnabled'] !== null) {
                $vpn['enabled'] = (bool) $attrCfg['vpnEnabled'];
            }
            if (array_key_exists('vpnDefaultBehavior', $attrCfg) && $attrCfg['vpnDefaultBehavior'] !== null) {
                $beh                     = strtolower((string) $attrCfg['vpnDefaultBehavior']);
                $vpn['default_behavior'] = in_array($beh, [
                    'allow',
                    'block',
                ], true) ? $beh : 'allow';
            }
            $result['filters']['vpn'] = $vpn;
        }

        return $this->DTOHydrat($result);
    }

    /**
     * @return array<string,mixed>
     */
    private function defaults(): array
    {
        return (array) $this->globalConfig;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function DTOHydrat(array $config): ResolvedGeoApiConfigDTO
    {
        $enabled              = (bool) ($config['enabled'] ?? true);
        $eventBridgeService   = isset($config['event_bridge_service']) && is_string($config['event_bridge_service']) ? $config['event_bridge_service'] : null;
        $providerFallbackMode = (bool) ($config['provider_fallback_mode'] ?? false);
        $redirectOnBan        = isset($config['redirect_on_ban']) && is_string($config['redirect_on_ban']) ? $config['redirect_on_ban'] : null;
        $logChannel           = is_string($config['log_channel'] ?? null) ? $config['log_channel'] : 'geolocator';
        $logLevel             = is_string($config['log_level'] ?? null) ? $config['log_level'] : 'warning';
        $simulate             = (bool) ($config['simulate'] ?? false);
        $cacheCfg             = is_array($config['cache'] ?? null) ? $config['cache'] : [];
        $cacheKeyStrategy     = is_string($cacheCfg['key_strategy'] ?? null) ? $cacheCfg['key_strategy'] : 'ip';
        $provider             = is_string($config['provider'] ?? null) ? $config['provider'] : null;
        $forceProvider        = is_string($config['forceProvider'] ?? null) ? $config['forceProvider'] : (is_string($config['force_provider'] ?? null) ? $config['force_provider'] : null);
        $cacheTtl             = isset($config['cacheTtl']) && is_int($config['cacheTtl']) ? $config['cacheTtl'] : (isset($config['cache_ttl']) && is_int($config['cache_ttl']) ? $config['cache_ttl'] : null);
        $blockOnError         = (bool) ($config['blockOnError'] ?? ($config['block_on_error'] ?? true));
        $exclusionKey         = is_string($config['exclusionKey'] ?? null) ? $config['exclusionKey'] : (is_string($config['exclusion_key'] ?? null) ? $config['exclusion_key'] : null);
        $providers            = is_array($config['providers'] ?? null) ? $config['providers'] : ['default' => 'findip', 'list' => []];
        $storageCfg           = is_array($config['storage'] ?? null) ? $config['storage'] : [];
        $dsnRaw               = $storageCfg['dsn'] ?? null;
        $storage              = ['dsn' => is_string($dsnRaw) ? $dsnRaw : null];
        $bansCfg              = is_array($config['bans'] ?? null) ? $config['bans'] : [];
        $maxAttempts          = isset($bansCfg['max_attempts']) && is_int($bansCfg['max_attempts']) ? $bansCfg['max_attempts'] : 10;
        $ttlInt               = $bansCfg['ttl'] ?? 3600;
        $ttl                  = is_int($ttlInt) ? $ttlInt : (is_string($ttlInt) && ctype_digit($ttlInt) ? (int) $ttlInt : 3600);
        $banDurationRaw       = $bansCfg['ban_duration'] ?? '1 hour';
        $banDuration          = is_string($banDurationRaw) ? $banDurationRaw : '1 hour';
        $bans                 = ['max_attempts' => $maxAttempts, 'ttl' => $ttl, 'ban_duration' => $banDuration];
        $filters              = is_array($config['filters'] ?? null) ? $config['filters'] : [];
        $trusted              = is_array($config['trusted'] ?? null) ? $config['trusted'] : ['headers' => [], 'proxies' => [], 'routes' => []];
        $exclusions           = is_array($config['exclusions'] ?? null) ? $config['exclusions'] : ['key_strategy' => 'ip'];

        return new ResolvedGeoApiConfigDTO(
            enabled: $enabled,
            eventBridgeService: $eventBridgeService,
            providerFallbackMode: $providerFallbackMode,
            redirectOnBan: $redirectOnBan,
            logChannel: $logChannel,
            logLevel: $logLevel,
            simulate: $simulate,
            cacheKeyStrategy: $cacheKeyStrategy,
            provider: $provider,
            forceProvider: $forceProvider,
            cacheTtl: $cacheTtl,
            blockOnError: $blockOnError,
            exclusionKey: $exclusionKey,
            providers: $providers,
            storage: $storage,
            bans: $bans,
            filters: $filters,
            trusted: $trusted,
            exclusions: $exclusions,
        );
    }

    private function warnIfUntrustedProxiesAndHeaders(Request $request): void
    {
        if (self::$trustedProxyWarningEmitted) {
            return;
        }
        $suspectHeaders = [
            'x-forwarded-for',
            'x-forwarded-proto',
            'x-forwarded-port',
            'x-forwarded-host',
            'x-real-ip',
            'forwarded',
            'cf-connecting-ip',
            'true-client-ip',
        ];
        $present = [];
        foreach ($suspectHeaders as $h) {
            if ($request->headers->has($h)) {
                $present[] = $h;
            }
        }
        if (!$present) {
            return;
        }
        $trustedProxies = Request::getTrustedProxies();
        if (is_array($trustedProxies) && count($trustedProxies) === 0) {
            $this->logger->warning('Forwarded headers present but no trusted proxies configured; potential IP spoofing risk', [
                'headers' => $present,
                'channel' => 'geolocator',
            ]);
            self::$trustedProxyWarningEmitted = true;
        }
    }

    /**
     * @param array<int,string> $patterns
     */
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
}

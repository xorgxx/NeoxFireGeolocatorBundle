<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('neox_fire_geolocator');
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                // Commutateur global
                ->booleanNode('enabled')->defaultTrue()->end()

                // Intégrations/contrôle
                ->scalarNode('event_bridge_service')->defaultNull()->end()
                ->booleanNode('provider_fallback_mode')->defaultFalse()->end()
                ->scalarNode('redirect_on_ban')->defaultNull()->end()

                // Journalisation
                ->scalarNode('log_channel')->defaultValue('neox_fire_geolocator')->end()
                ->scalarNode('log_level')->defaultValue('warning')->end()

                // Mode simulation
                ->booleanNode('simulate')->defaultFalse()->end()

                // Top-level provider overrides (can be overridden by attributes)
                ->scalarNode('provider')->defaultNull()->end()
                ->scalarNode('force_provider')->defaultNull()->end()
                ->integerNode('cache_ttl')->defaultNull()->end()
                ->booleanNode('block_on_error')->defaultTrue()->end()
                ->scalarNode('exclusion_key')->defaultNull()->end()

                // Providers configuration
                ->arrayNode('providers')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default')->defaultValue('findip')->end()
                        ->arrayNode('list')
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('dsn')->isRequired()->end()
                                    ->arrayNode('variables')
                                        ->normalizeKeys(false)
                                        ->scalarPrototype()->end()
                                        ->defaultValue([])
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->always(function (array $v) {
                            $list = $v['list'] ?? [];
                            if (!\is_array($list) || count($list) === 0) {
                                throw new \InvalidArgumentException('neox_fire_geolocator.providers.list must contain at least one provider');
                            }
                            $default = $v['default'] ?? null;
                            if ($default !== null && !array_key_exists($default, $list)) {
                                throw new \InvalidArgumentException(sprintf('neox_fire_geolocator.providers.default "%s" is not defined in providers.list', (string) $default));
                            }

                            $allowedSchemes = ['findip', 'ipinfo', 'ipapi'];
                            foreach ($list as $name => $cfg) {
                                $dsn = $cfg['dsn'] ?? '';
                                if (!\is_string($dsn) || $dsn === '') {
                                    throw new \InvalidArgumentException(sprintf('Provider "%s" must define a non-empty "dsn" string', (string) $name));
                                }
                                $dsn = trim($dsn);
                                if (strpos($dsn, '+') === false) {
                                    throw new \InvalidArgumentException(sprintf('Provider "%s" DSN must start with a provider scheme followed by "+" (e.g., findip+https://...)', (string) $name));
                                }
                                [$scheme, $endpoint] = explode('+', $dsn, 2);
                                $scheme              = strtolower($scheme);
                                $endpoint            = trim($endpoint);
                                if (!in_array($scheme, $allowedSchemes, true)) {
                                    throw new \InvalidArgumentException(sprintf('Provider "%s" uses unsupported scheme "%s". Allowed: %s', (string) $name, $scheme, implode(', ', $allowedSchemes)));
                                }
                                if (!preg_match('#^https?://#i', $endpoint)) {
                                    throw new \InvalidArgumentException(sprintf('Provider "%s" endpoint must be http(s) URL after scheme+: got "%s"', (string) $name, $endpoint));
                                }
                                if (strpos($endpoint, '{ip}') === false) {
                                    throw new \InvalidArgumentException(sprintf('Provider "%s" endpoint must contain "{ip}" placeholder', (string) $name));
                                }
                                // write back normalized DSN
                                $v['list'][$name]['dsn'] = $scheme . '+' . $endpoint;
                                $vars                    = $cfg['variables'] ?? [];
                                if (in_array($scheme, ['findip', 'ipinfo'], true)) {
                                    if (!\is_array($vars) || !array_key_exists('token', $vars)) {
                                        throw new \InvalidArgumentException(sprintf('Provider "%s" requires a "variables.token" value', (string) $name));
                                    }
                                    $tok = is_scalar($vars['token']) ? (string) $vars['token'] : '';
                                    $tok = trim($tok);
                                    if ($tok === '') {
                                        throw new \InvalidArgumentException(sprintf('Provider "%s" requires a non-empty "variables.token" value', (string) $name));
                                    }
                                    $v['list'][$name]['variables']['token'] = $tok;
                                }
                            }

                            return $v;
                        })
                    ->end()
                ->end()

                // Cache tuning
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('context_ttl')->defaultValue(300)->end()
                        ->scalarNode('key_strategy')
                            ->defaultValue('ip')
                            ->validate()
                                ->ifNotInArray(['ip', 'session'])
                                ->thenInvalid('Invalid key_strategy "%s". Allowed values: ip, session')
                            ->end()
                        ->end()
                        ->scalarNode('redis_dsn')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(function ($v) { return is_string($v) && str_starts_with($v, 'redis://') && !Configuration::isValidRedisDsn($v); })
                                ->thenInvalid('Invalid cache.redis_dsn "%s". Expected format: redis://[:password@]host[:port][/db]')
                            ->end()
                        ->end()
                    ->end()
                ->end()

                // Exclusions
                ->arrayNode('exclusions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('key_strategy')
                            ->defaultValue('ip')
                            ->validate()
                                ->ifNotInArray(['ip', 'session'])
                                ->thenInvalid('Invalid exclusions.key_strategy "%s". Allowed: ip, session')
                            ->end()
                        ->end()
                    ->end()
                ->end()

                // Stockage
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('dsn')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(function ($v) { return is_string($v) && str_starts_with($v, 'redis://') && !Configuration::isValidRedisDsn($v); })
                                ->thenInvalid('Invalid storage.dsn "%s" for Redis. Expected format: redis://[:password@]host[:port][/db]')
                            ->end()
                        ->end()
                    ->end()
                ->end()

                // Bans
                ->arrayNode('bans')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_attempts')->defaultValue(10)->end()
                        ->integerNode('ttl')->defaultValue(3600)->end()
                        ->scalarNode('ban_duration')->defaultValue('1 hour')->end()
                    ->end()
                ->end()

                // Filtres
                ->arrayNode('filters')
                    ->addDefaultsIfNotSet()
                    ->children()
                        // Navigator
                        ->arrayNode('navigator')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                                ->scalarNode('default_behavior')
                                    ->beforeNormalization()->ifString()->then(fn ($v) => strtolower(trim((string) $v)))->end()
                                    ->defaultValue('allow')
                                    ->validate()
                                        ->ifNotInArray(['allow', 'block'])
                                        ->thenInvalid('Invalid navigator.default_behavior "%s". Allowed values: allow, block')
                                    ->end()
                                ->end()
                                ->arrayNode('rules')
                                    ->scalarPrototype()->end()
                                    ->defaultValue(['+chrome', '+firefox', '+safari', '+edge', '-android', '-mobile safari'])
                                ->end()
                                ->integerNode('priority')->defaultNull()->end()
                            ->end()
                        ->end()

                        // Country
                        ->arrayNode('country')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('default_behavior')
                                    ->beforeNormalization()->ifString()->then(fn ($v) => strtolower(trim((string) $v)))->end()
                                    ->defaultValue('allow')
                                    ->validate()
                                        ->ifNotInArray(['allow', 'block'])
                                        ->thenInvalid('Invalid country.default_behavior "%s". Allowed values: allow, block')
                                    ->end()
                                ->end()
                                ->arrayNode('rules')->scalarPrototype()->end()->defaultValue([])->end()
                                ->integerNode('priority')->defaultNull()->end()
                            ->end()
                        ->end()

                        // IP
                        ->arrayNode('ip')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('default_behavior')
                                    ->beforeNormalization()->ifString()->then(fn ($v) => strtolower(trim((string) $v)))->end()
                                    ->defaultValue('allow')
                                    ->validate()
                                        ->ifNotInArray(['allow', 'block'])
                                        ->thenInvalid('Invalid ip.default_behavior "%s". Allowed values: allow, block')
                                    ->end()
                                ->end()
                                ->arrayNode('rules')->scalarPrototype()->end()->defaultValue([])->end()
                                ->integerNode('priority')->defaultNull()->end()
                            ->end()
                        ->end()

                        // Crawler
                        ->arrayNode('crawler')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                                ->booleanNode('allow_known')->defaultTrue()->end()
                                ->scalarNode('default_behavior')
                                    ->beforeNormalization()->ifString()->then(fn ($v) => strtolower(trim((string) $v)))->end()
                                    ->defaultValue('allow')
                                    ->validate()
                                        ->ifNotInArray(['allow', 'block'])
                                        ->thenInvalid('Invalid crawler.default_behavior "%s". Allowed values: allow, block')
                                    ->end()
                                ->end()
                                ->arrayNode('rules')->scalarPrototype()->end()->defaultValue([])->end()
                                ->integerNode('priority')->defaultNull()->end()
                            ->end()
                        ->end()

                        // VPN/Proxy
                        ->arrayNode('vpn')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                                ->scalarNode('default_behavior')
                                    ->beforeNormalization()->ifString()->then(fn ($v) => strtolower(trim((string) $v)))->end()
                                    ->defaultValue('allow')
                                    ->validate()
                                        ->ifNotInArray(['allow', 'block'])
                                        ->thenInvalid('Invalid vpn.default_behavior "%s". Allowed values: allow, block')
                                    ->end()
                                ->end()
                                ->integerNode('priority')->defaultNull()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()

                // Trusted
                ->arrayNode('trusted')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('headers')
                            ->scalarPrototype()->end()
                            ->defaultValue([
                                'X-Forwarded-For',
                                'X-Real-IP',
                                'X-Forwarded-Host',
                                'X-Forwarded-Proto',
                                'CF-Connecting-IP',
                                'True-Client-IP',
                                'Fastly-Client-Ip',
                                'X-Client-IP',
                            ])
                        ->end()
                        ->arrayNode('proxies')
                            ->scalarPrototype()->end()
                            ->defaultValue([
                                '127.0.0.1',
                                '10.0.0.0/8',
                                '172.16.0.0/12',
                                '192.168.0.0/16',
                            ])
                        ->end()
                        ->arrayNode('routes')
                            ->scalarPrototype()->end()
                            ->defaultValue([
                                '_wdt*',
                                '_profiler*',
                                'symfony_*',
                                'api_doc*',
                                'fos_js_*',
                            ])
                        ->end()
                    ->end()
                ->end()

                // Maintenance mode
                ->arrayNode('maintenance')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->always(function ($m) {
                            if (is_array($m) && isset($m['retry_after']) && is_int($m['retry_after']) && $m['retry_after'] < 0) {
                                throw new \InvalidArgumentException('maintenance.retry_after must be greater than or equal to 0');
                            }

                            return $m;
                        })
                    ->end()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->arrayNode('allowed_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                        ->arrayNode('paths_whitelist')
                            ->scalarPrototype()->end()
                            ->defaultValue(['/login', '/_profiler', '/_wdt', '/healthz', '/assets'])
                        ->end()
                        ->arrayNode('ips_whitelist')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->integerNode('retry_after')
                            ->defaultValue(600)
                        ->end()
                        ->scalarNode('message')->defaultNull()->end()
                        ->scalarNode('template')->defaultNull()->end()
                    ->end()
                    ->validate()
                        ->always(function (array $m) {
                            if (isset($m['retry_after']) && is_int($m['retry_after']) && $m['retry_after'] < 0) {
                                throw new \InvalidArgumentException('maintenance.retry_after must be greater than or equal to 0');
                            }

                            return $m;
                        })
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }

    public static function isValidRedisDsn(string $dsn): bool
    {
        $dsn = trim($dsn);
        if ($dsn === '' || stripos($dsn, 'redis://') !== 0) {
            return false;
        }
        $parts = parse_url($dsn);
        if (!is_array($parts)) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'redis') {
            return false;
        }
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return false;
        }
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                return false;
            }
        }
        if (!empty($parts['path'])) {
            $db = ltrim($parts['path'], '/');
            if ($db !== '' && !ctype_digit($db)) {
                return false;
            }
        }

        return true;
    }
}

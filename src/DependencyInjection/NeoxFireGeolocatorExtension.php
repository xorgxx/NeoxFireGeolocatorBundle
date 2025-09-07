<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\DependencyInjection;

use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class NeoxFireGeolocatorExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        // Candidats de dossiers pour Twig, traductions (i18n) et assets publics
        $base           = \dirname(__DIR__, 2);
        $twigCandidates = [
            $base . '/templates',
        ];
        $i18nCandidates = [
            $base . '/translations',
        ];
        $publicAssetsDir = $base . '/public';

        // Enregistre automatiquement le namespace Twig "NeoxFireGeolocator"
        if ($container->hasExtension('twig')) {
            $paths = [];
            foreach ($twigCandidates as $path) {
                if (is_dir($path)) {
                    // Utiliser le format liste pour éviter l'écrasement et déclarer 2 namespaces pour le même chemin
                    //                    $paths[] = ['value' => $path, 'namespace' => 'Geolocator'];
                    $paths[] = ['value' => $path, 'namespace' => 'NeoxFireGeolocator'];
                }
            }
            if ($paths !== []) {
                $container->prependExtensionConfig('twig', [
                    'paths' => $paths,
                ]);
            }
        }

        // Enregistre automatiquement les chemins de traductions (i18n)
        if ($container->hasExtension('framework')) {
            $translationPaths = [];
            foreach ($i18nCandidates as $path) {
                if (is_dir($path)) {
                    $translationPaths[] = $path;
                }
            }
            if ($translationPaths !== []) {
                $container->prependExtensionConfig('framework', [
                    'translator' => [
                        'paths' => $translationPaths,
                    ],
                ]);
            }

            // Expose le répertoire public du bundle aux systèmes d'assets
            if (is_dir($publicAssetsDir)) {
                // 1) Asset Component (classique): déclare un package "neox_fire_geolocator" avec base_path
                $container->prependExtensionConfig('framework', [
                    'assets' => [
                        'packages' => [
                            'neox_fire_geolocator' => [
                                'base_path' => '/bundles/FireGeolocatorBundle',
                            ],
                        ],
                    ],
                ]);

                // 2) AssetMapper (Symfony 6.3+): ajoute le chemin mappé si activé
                // Cette config est inoffensive si asset_mapper n'est pas utilisé.
                $container->prependExtensionConfig('framework', [
                    'asset_mapper' => [
                        'paths' => [
                            [
                                'value'     => $publicAssetsDir,
                                'namespace' => 'neox_fire_geolocator',
                            ],
                        ],
                    ],
                ]);
            }
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $container->setParameter('neox_fire_geolocator.config', $config);

        // Extract filters priority map from configuration and expose it as a parameter
        $priorities = [];
        if (isset($config['filters']) && is_array($config['filters'])) {
            foreach ($config['filters'] as $code => $cfg) {
                if (is_array($cfg) && array_key_exists('priority', $cfg) && $cfg['priority'] !== null) {
                    $priorities[strtolower((string) $code)] = (int) $cfg['priority'];
                }
            }
        }
        $container->setParameter('neox_fire_geolocator.filters_priority', $priorities);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        // Exposer aussi toutes les clés enfants: neox_fire_geolocator.enabled, neox_fire_geolocator.providers.*, etc.
        $this->registerConfigParameters($container, $config, 'neox_fire_geolocator');

        // Configure dedicated Redis PSR-6 pool if a Redis DSN is provided
        // Priority: neox_fire_geolocator.cache.redis_dsn > neox_fire_geolocator.storage.dsn (if redis)
        $cacheDsn   = $config['cache']['redis_dsn'] ?? null;
        $storageDsn = $config['storage']['dsn']     ?? null;
        if (is_string($cacheDsn) && str_starts_with($cacheDsn, 'redis://')) {
            $this->configureRedis($container, $cacheDsn);
        } elseif (is_string($storageDsn) && str_starts_with($storageDsn, 'redis://')) {
            $this->configureRedis($container, $storageDsn);
        } else {
            // Fallback: ensure the pool id exists to avoid container failures when no Redis DSN is set
            if (!$container->hasDefinition('neox_fire_geolocator.cache_pool') && !$container->hasAlias('neox_fire_geolocator.cache_pool')) {
                $container->setAlias('neox_fire_geolocator.cache_pool', 'cache.app')->setPublic(true);
            }
        }
    }

    private function registerConfigParameters(ContainerBuilder $container, array $config, string $prefix = 'neox_fire_geolocator'): void
    {
        foreach ($config as $key => $value) {
            $paramKey = $prefix . '.' . $key;

            if (is_array($value)) {
                // Dépose le tableau à ce niveau
                $container->setParameter($paramKey, $value);
                // Et expose récursivement les sous-clés associatives
                if (!$this->isSimpleArray($value)) {
                    $this->registerConfigParameters($container, $value, $paramKey);
                }
            } else {
                $container->setParameter($paramKey, $value);
            }
        }
    }

    private function isSimpleArray(array $array): bool
    {
        if (empty($array)) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    // Creates a Redis connection from DSN; kept minimal for connectivity
    public static function createRedisInstance(string $dsn): \Redis
    {
        $redis   = new \Redis();
        $parts   = parse_url($dsn);
        $host    = $parts['host'] ?? '127.0.0.1';
        $port    = isset($parts['port']) ? (int) $parts['port'] : 6379;
        $timeout = 1.5;
        $redis->connect($host, $port, $timeout);
        if (!empty($parts['pass'])) {
            $redis->auth($parts['pass']);
        }
        if (!empty($parts['path'])) {
            $db = ltrim($parts['path'], '/');
            if ($db !== '' && ctype_digit($db)) {
                $redis->select((int) $db);
            }
        }

        return $redis;
    }

    private function configureRedis(ContainerBuilder $container, ?string $dsn): void
    {
        if ($dsn === null) {
            return;
        }

        // Connexion Redis
        $redisInstanceDefinition = new Definition(\Redis::class);
        $redisInstanceDefinition->setFactory([self::class, 'createRedisInstance']);
        $redisInstanceDefinition->setArguments([$dsn]);
        $redisInstanceDefinition->setPublic(false);
        $container->setDefinition('neox_fire_geolocator.redis_instance', $redisInstanceDefinition);

        // (Optionnel) Adapter Redis bas niveau
        $redisAdapterDefinition = new Definition(RedisAdapter::class);
        $redisAdapterDefinition->setArguments([new Reference('neox_fire_geolocator.redis_instance')]);
        $redisAdapterDefinition->setPublic(false);
        $container->setDefinition('neox_fire_geolocator.redis_adapter', $redisAdapterDefinition);

        // Pool PSR-6 dédié "neox_fire_geolocator" (namespace lisible + TTL par défaut 0)
        $pool = new Definition(RedisAdapter::class);
        $pool->setArguments([
            new Reference('neox_fire_geolocator.redis_instance'), // provider
            'neox_fire_geolocator',                                 // namespace
            0,                                                      // default_lifetime
        ]);
        $pool->setPublic(true);
        $container->setDefinition('neox_fire_geolocator.cache_pool', $pool);

        // (Optionnel) faire pointer l’autowiring PSR-6 sur ce pool
        // $container->setAlias(\Psr\Cache\CacheItemPoolInterface::class, 'neox_fire_geolocator.cache_pool')->setPublic(true);
    }
}

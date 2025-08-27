<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\CacheWarmer;

use Neox\FireGeolocatorBundle\Service\Config\GeoAttributeResolver;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Routing\RouterInterface;

final class GeoAttributeCacheWarmer implements CacheWarmerInterface
{
    public function __construct(
        private RouterInterface $router,
        private GeoAttributeResolver $resolver,
    ) {
    }

    public function isOptional(): bool
    {
        // Optional: app can run without warmed cache
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $map = [];
        try {
            $routes = $this->router->getRouteCollection();
            foreach ($routes as $route) {
                $controller = $route->getDefault('_controller');
                if (!is_string($controller) || !str_contains($controller, '::')) {
                    continue;
                }
                [$class, $method] = explode('::', $controller, 2);
                // Skip if class does not exist
                if (!class_exists($class)) {
                    continue;
                }
                try {
                    $resolved = $this->resolver->resolveForController($class, $method);
                } catch (\Throwable) {
                    continue;
                }
                $map[$class . '::' . $method] = $resolved;
            }
        } catch (\Throwable) {
            // Ignore warmer errors; keep optional
        }

        // Persist to a PHP file for fast load by the resolver constructor
        $targetDir = $buildDir ?: $cacheDir;
        $file      = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'geolocator_attr_map.php';
        $php       = '<?php return ' . var_export($map, true) . ';';
        @file_put_contents($file, $php);

        // Also prime current process to benefit immediately
        if ($map) {
            GeoAttributeResolver::prime($map);
        }

        return [];
    }
}

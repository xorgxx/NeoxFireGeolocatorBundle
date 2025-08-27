<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Config;

use Neox\FireGeolocatorBundle\Attribute\GeoApi as GeoApiAttr;

/**
 * Résout les attributs GeoApi présents sur une classe/méthode de contrôleur.
 * Ne dépend que des attributes PHP (les annotations Doctrine sont optionnelles ailleurs).
 */
final class GeoAttributeResolver
{
    /**
     * Small in-memory cache to avoid repeated reflection on long-running processes.
     * Keyed by "Class::method".
     */
    private array $cache = [];

    /**
     * Precomputed map populated by the cache warmer. Keyed by "Class::method".
     * When available, reflection is fully skipped.
     */
    private static ?array $precomputed = null;

    private ?string $cacheDir = null;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir;
        if (self::$precomputed === null && $this->cacheDir) {
            $file = rtrim($this->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'geolocator_attr_map.php';
            if (is_file($file)) {
                $map = include $file;
                if (is_array($map)) {
                    self::$precomputed = $map;
                }
            }
        }
    }

    public static function prime(array $map): void
    {
        self::$precomputed = $map;
    }

    /**
     * @return array{0:string,1:string}|null [class, method] ou null si non parsable
     */
    public function parseController(mixed $controllerCallable): ?array
    {
        if (is_string($controllerCallable) && str_contains($controllerCallable, '::')) {
            [$class, $method] = explode('::', $controllerCallable, 2);

            return [$class, $method];
        }

        if (is_array($controllerCallable) && count($controllerCallable) === 2) {
            $class  = is_object($controllerCallable[0]) ? $controllerCallable[0]::class : (string) $controllerCallable[0];
            $method = (string) $controllerCallable[1];

            return [$class, $method];
        }

        return null;
    }

    /**
     * Retourne la config issue des attributs (classe + méthode), fusionnée (méthode prioritaire).
     *
     * @throws \ReflectionException
     *
     * @return array{config:?array} ex: ['config' => [...]] ou ['config' => null]
     */
    public function resolveForController(string $class, string $method): array
    {
        $key = $class . '::' . $method;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $classCfg  = class_exists($class) ? $this->readGeoApiAttributesFromClass($class) : null;
        $methodCfg = class_exists($class) ? $this->readGeoApiAttributesFromMethod($class, $method) : null;

        $merged = $this->mergeAttributeConfigs($classCfg, $methodCfg);

        return $this->cache[$key] = ['config' => $merged];
    }

    /**
     * @param class-string $class
     *
     * @throws \ReflectionException
     */
    private function readGeoApiAttributesFromClass(string $class): ?array
    {
        if (!class_exists($class)) {
            return null;
        }
        $ref = new \ReflectionClass($class);

        return $this->extracted($ref);
    }

    /**
     * @throws \ReflectionException
     */
    private function readGeoApiAttributesFromMethod(string $class, string $method): ?array
    {
        $ref = new \ReflectionMethod($class, $method);

        return $this->extracted($ref);
    }

    /**
     * Fusionne deux configs attributaires (classe puis méthode en priorité).
     */
    private function mergeAttributeConfigs(?array $classCfg, ?array $methodCfg): ?array
    {
        if (!$classCfg && !$methodCfg) {
            return null;
        }

        $result = $classCfg ?? [];

        if ($methodCfg) {
            foreach ($methodCfg as $k => $v) {
                if ($v === null) {
                    continue;
                }
                $result = $this->getArr($k, $v, $result);
            }
        }

        return $result;
    }

    /**
     * Aplatis une liste de lignes en une seule config (écrasement simple, dernière occurrence prioritaire).
     * Utilisé pour gérer les attributs répétables.
     */
    private function flatten(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach ($row as $k => $v) {
                $out = $this->getArr($k, $v, $out);
            }
        }

        return $out;
    }

    private function extracted(\ReflectionMethod|\ReflectionClass $ref): ?array
    {
        $attrs = $ref->getAttributes(GeoApiAttr::class);
        if (!$attrs) {
            return null;
        }

        $data = [];
        foreach ($attrs as $a) {
            /** @var GeoApiAttr $inst */
            $inst   = $a->newInstance();
            $row    = get_object_vars($inst);
            $data[] = array_filter($row, static fn ($v) => $v !== null);
        }

        return $this->flatten($data);
    }

    private function getArr(int|string $k, mixed $v, array $result): array
    {
        if ($k === 'filters' && is_array($v)) {
            $result['filters'] ??= [];
            foreach ($v as $cat => $f) {
                if (!isset($result['filters'][$cat]) || !is_array($result['filters'][$cat])) {
                    $result['filters'][$cat] = [];
                }
                foreach ($f as $sk => $sv) {
                    if ($sk === 'rules' && is_array($sv)) {
                        $cur                              = $result['filters'][$cat]['rules'] ?? [];
                        $result['filters'][$cat]['rules'] = $this->mergeRules($cur, $sv);
                    } else {
                        $result['filters'][$cat][$sk] = $sv;
                    }
                }
            }
        } elseif ($k === 'excludeRoutes' && is_array($v)) {
            $cur                     = $result['excludeRoutes'] ?? [];
            $result['excludeRoutes'] = array_values(array_unique(array_merge($cur, $v)));
        } elseif ($k === 'countries' && is_array($v)) {
            $this->ensureFilterRules($result, 'country', $v);
        } elseif ($k === 'ips' && is_array($v)) {
            $this->ensureFilterRules($result, 'ip', $v);
        } elseif ($k === 'crawlers' && is_array($v)) {
            // selon votre config, la clé peut être 'crawler' ou 'crawler_filter'
            $cat = isset(($result['filters'] ?? [])['crawler_filter']) ? 'crawler_filter' : 'crawler';
            $this->ensureFilterRules($result, $cat, $v);
        } else {
            $result[$k] = $v;
        }

        return $result;
    }

    /**
     * Merge two rule lists with normalization on "+/-" prefixes. Later rules overwrite by key.
     */
    public function mergeRules(array $current, array $incoming): array
    {
        $map = [];
        foreach ($current as $r) {
            if (is_string($r) && $r !== '') {
                $map[ltrim($r, '+-')] = $r;
            }
        }
        foreach ($incoming as $r) {
            if (!is_string($r) || $r === '') {
                continue;
            }
            $map[ltrim($r, '+-')] = $r;
        }

        return array_values($map);
    }

    /**
     * Ensure that $dst['filters'][$category]['rules'] exists and merge provided rules into it.
     */
    public function ensureFilterRules(array &$dst, string $category, array $rules): void
    {
        $dst['filters']            ??= [];
        $dst['filters'][$category] ??= [];
        $cur                                = $dst['filters'][$category]['rules'] ?? [];
        $dst['filters'][$category]['rules'] = $this->mergeRules($cur, $rules);
    }
}

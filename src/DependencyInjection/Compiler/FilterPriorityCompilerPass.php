<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Rewrites the priority attribute of geolocator filters tags from configuration map.
 * Rule: higher priority executes earlier. If no priority configured for a code, keep the tag value.
 */
final class FilterPriorityCompilerPass implements CompilerPassInterface
{
    private const TAG = 'neox_fire_geolocator.filter';

    public function process(ContainerBuilder $container): void
    {
        // Paramètres supportés (ordre de priorité)
        $paramCandidates = [
            'geolocator.filters_priority',
            'neox_fire_geolocator.filters_priority',
        ];

        $priorityMap = [];
        $found       = false;
        foreach ($paramCandidates as $paramName) {
            if ($container->hasParameter($paramName)) {
                $value = $container->getParameter($paramName);
                if (\is_array($value) && $value !== []) {
                    // geolocator.* doit primer sur neox_fire_geolocator.*
                    $priorityMap = $priorityMap + $value;
                    $found       = true;
                }
            }
        }

        if (!$found || $priorityMap === []) {
            return; // rien à appliquer
        }

        // Normalisation des clés en minuscule
        $normalized = [];
        foreach ($priorityMap as $k => $v) {
            $normalized[\strtolower((string) $k)] = (int) $v;
        }

        // Tags supportés (ordre de préférence)
        $tagCandidates = [
            'xorgxx.geolocator.filter',
            'neox_fire_geolocator.filter',
        ];

        foreach ($tagCandidates as $tagName) {
            // Services tagués avec ce nom
            foreach ($container->findTaggedServiceIds($tagName) as $serviceId => $tags) {
                $definition = $container->getDefinition($serviceId);
                $class      = $definition->getClass() ?: $serviceId;

                // Récupérer toutes les occurrences du tag
                $allTags = $definition->getTags();
                if (!isset($allTags[$tagName])) {
                    continue;
                }

                $updated = false;
                foreach ($allTags[$tagName] as $idx => $attributes) {
                    // Déterminer le "code": attribut 'code' prioritaire, sinon dérivé du nom de classe
                    $code = null;
                    if (isset($attributes['code']) && \is_string($attributes['code']) && $attributes['code'] !== '') {
                        $code = \strtolower($attributes['code']);
                    } else {
                        $short = \is_string($class) ? \trim($class, '\\') : '';
                        if ($short !== '') {
                            $pos   = strrpos($short, '\\');
                            $short = $pos !== false ? substr($short, $pos + 1) : $short;
                            // Enlever le suffixe "Filter" le cas échéant
                            if (\function_exists('str_ends_with')) {
                                if (str_ends_with($short, 'Filter')) {
                                    $short = substr($short, 0, -6);
                                }
                            } else {
                                if (substr($short, -6) === 'Filter') {
                                    $short = substr($short, 0, -6);
                                }
                            }
                            $code = \strtolower($short);
                        }
                    }

                    if ($code !== null && array_key_exists($code, $normalized)) {
                        $allTags[$tagName][$idx]['priority'] = $normalized[$code];
                        $updated                             = true;
                    }
                }

                if ($updated) {
                    $definition->setTags($allTags);
                }
            }
        }
    }
}

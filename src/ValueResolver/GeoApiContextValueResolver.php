<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\ValueResolver;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

class GeoApiContextValueResolver implements ValueResolverInterface
{
    /**
     * @return array<int, GeoApiContextDTO>
     */
    public function resolve(Request $request, ArgumentMetadata $parameter): array
    {
        $type = $parameter->getType();
        if ($type === GeoApiContextDTO::class) {
            $ctx = $request->attributes->get('geolocator_context');
            if ($ctx instanceof GeoApiContextDTO) {
                return [$ctx];
            }
        }

        return [];
    }
}

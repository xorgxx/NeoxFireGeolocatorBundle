<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractFilter implements FilterInterface
{
    public function __construct(private readonly bool $enabled = true)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    abstract public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO;
}

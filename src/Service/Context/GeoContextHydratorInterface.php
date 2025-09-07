<?php

namespace Neox\FireGeolocatorBundle\Service\Context;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;

interface GeoContextHydratorInterface
{
    /**
     * Reconstitutes a GeoApiContextDTO from a sanitized array (no PII) and the current request IP.
     * Must never persist the IP; this is only for in-memory runtime use.
     */
    public function hydrateFromSanitized(string $ip, array $data): GeoApiContextDTO;
}

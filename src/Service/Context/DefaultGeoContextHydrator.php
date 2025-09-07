<?php

namespace Neox\FireGeolocatorBundle\Service\Context;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;

final class DefaultGeoContextHydrator implements GeoContextHydratorInterface
{
    public function hydrateFromSanitized(string $ip, array $d): GeoApiContextDTO
    {
        return new GeoApiContextDTO(
            ip: $ip,
            country: $d['country']         ?? null,
            countryCode: $d['countryCode'] ?? null,
            region: $d['region']           ?? null,
            city: $d['city']               ?? null,
            lat: isset($d['lat']) ? (float) $d['lat'] : null,
            lon: isset($d['lon']) ? (float) $d['lon'] : null,
            isp: $d['isp']         ?? null,
            asn: $d['asn']         ?? null,
            proxy: $d['proxy']     ?? null,
            hosting: $d['hosting'] ?? null,
            raw: is_array($d['raw'] ?? null) ? $d['raw'] : $d,
        );
    }
}

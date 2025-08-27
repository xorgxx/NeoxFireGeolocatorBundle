<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Provider\Mapper;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;

class FindIpMapper
{
    public function map(array $data, string $ip): GeoApiContextDTO
    {
        return new GeoApiContextDTO(
            ip: $ip,
            country: $data['country']         ?? null,
            countryCode: $data['countryCode'] ?? null,
            region: $data['regionName']       ?? null,
            city: $data['city']               ?? null,
            lat: isset($data['latitude']) ? (float) $data['latitude'] : null,
            lon: isset($data['longitude']) ? (float) $data['longitude'] : null,
            isp: $data['isp']         ?? null,
            asn: $data['asn']         ?? null,
            proxy: $data['proxy']     ?? null,
            hosting: $data['hosting'] ?? null,
            raw: $data
        );
    }
}

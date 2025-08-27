<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Provider\Mapper;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;

class IpApiMapper
{
    public function map(array $data, string $ip): GeoApiContextDTO
    {
        return new GeoApiContextDTO(
            ip: $ip,
            country: $data['country']         ?? null,
            countryCode: $data['countryCode'] ?? null,
            region: $data['regionName']       ?? null,
            city: $data['city']               ?? null,
            lat: isset($data['lat']) ? (float) $data['lat'] : null,
            lon: isset($data['lon']) ? (float) $data['lon'] : null,
            isp: $data['isp']         ?? null,
            asn: $data['as']          ?? null,
            proxy: $data['proxy']     ?? null,
            hosting: $data['hosting'] ?? null,
            raw: $data
        );
    }
}

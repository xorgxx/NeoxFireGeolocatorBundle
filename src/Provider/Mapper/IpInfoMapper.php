<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Provider\Mapper;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;

class IpInfoMapper
{
    public function map(array $data, string $ip): GeoApiContextDTO
    {
        return new GeoApiContextDTO(
            ip: $ip,
            country: $data['country']     ?? null,
            countryCode: $data['country'] ?? null,
            region: $data['region']       ?? null,
            city: $data['city']           ?? null,
            lat: isset($data['loc']) && str_contains($data['loc'], ',') ? (float) explode(',', $data['loc'])[0] : null,
            lon: isset($data['loc']) && str_contains($data['loc'], ',') ? (float) explode(',', $data['loc'])[1] : null,
            isp: $data['org']                    ?? null,
            asn: $data['asn']                    ?? null,
            proxy: $data['privacy']['proxy']     ?? null,
            hosting: $data['privacy']['hosting'] ?? null,
            raw: $data
        );
    }
}

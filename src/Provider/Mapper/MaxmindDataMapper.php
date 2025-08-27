<?php

namespace Neox\FireGeolocatorBundle\Provider\Mapper;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;

class MaxmindDataMapper
{
    public function map(array $data, string $ip): GeoApiContextDTO
    {
        // Country code ISO2 (ex: FR)
        $countryCode = $data['country']['iso_code'] ?? null;

        // Country name: préfère le FR puis EN
        $countryName = $data['country']['names']['fr']
            ?? $data['country']['names']['en']
            ?? null;

        // Region: première subdivision si présente (préfère FR puis EN)
        $region = null;
        if (!empty($data['subdivisions'][0])) {
            $sub    = $data['subdivisions'][0];
            $region = $sub['names']['fr']
                ?? $sub['names']['en']
                ?? ($sub['iso_code'] ?? null);
        }

        // Ville: préfère le FR puis EN
        $city = $data['city']['names']['fr']
            ?? $data['city']['names']['en']
            ?? null;

        // Coordonnées
        $lat = isset($data['location']['latitude']) ? (float) $data['location']['latitude'] : null;
        $lon = isset($data['location']['longitude']) ? (float) $data['location']['longitude'] : null;

        // Réseau / ISP / ASN
        $isp = $data['traits']['isp'] ?? ($data['traits']['organization'] ?? null);

        // ASN: number -> string (optionnellement préfixé "AS")
        $asnNumber = $data['traits']['autonomous_system_number'] ?? null;
        $asn       = is_numeric($asnNumber) ? ('AS' . (string) $asnNumber) : null;

        // Proxy / Hosting: non fournis dans cet exemple -> null
        $proxy   = $data['traits']['proxy']   ?? null;
        $hosting = $data['traits']['hosting'] ?? null;

        return new GeoApiContextDTO(
            ip: $ip,
            country: $countryName,
            countryCode: $countryCode,
            region: $region,
            city: $city,
            lat: $lat,
            lon: $lon,
            isp: $isp,
            asn: $asn,
            proxy: $proxy,
            hosting: $hosting,
            raw: $data
        );
    }
}

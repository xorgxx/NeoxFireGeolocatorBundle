<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\DTO;

class GeoApiContextDTO
{
    public function __construct(
        public string $ip,
        public ?string $country = null,
        public ?string $countryCode = null,
        public ?string $region = null,
        public ?string $city = null,
        public ?float $lat = null,
        public ?float $lon = null,
        public ?string $isp = null,
        public ?string $asn = null,
        public ?bool $proxy = null,
        public ?bool $hosting = null,
        public array $raw = []
    ) {
    }
}

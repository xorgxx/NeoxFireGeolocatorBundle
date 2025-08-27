<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\DTO;

class ProviderResultDTO
{
    public function __construct(
        public bool $ok,
        public ?GeoApiContextDTO $context = null,
        public ?string $error = null
    ) {
    }
}

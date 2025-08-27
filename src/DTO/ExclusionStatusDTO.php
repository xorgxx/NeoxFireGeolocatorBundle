<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\DTO;

class ExclusionStatusDTO
{
    public function __construct(
        public bool $excluded,
        public ?string $key = null,
        public ?int $ttl = null
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\DTO;

class AuthorizationDTO
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
        public ?string $blockingFilter = null
    ) {
    }
}

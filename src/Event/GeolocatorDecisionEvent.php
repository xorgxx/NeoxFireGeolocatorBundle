<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Event;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Symfony\Contracts\EventDispatcher\Event;

class GeolocatorDecisionEvent extends Event
{
    public function __construct(public AuthorizationDTO $auth, public ?GeoApiContextDTO $context)
    {
    }
}

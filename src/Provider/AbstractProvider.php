<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Provider;

use Neox\FireGeolocatorBundle\DTO\ProviderResultDTO;

abstract class AbstractProvider
{
    abstract public function fetch(string $ip): ProviderResultDTO;
}

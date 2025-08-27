<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Traits;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

trait ArrayPsr6PoolTrait
{
    protected function arrayPool(): CacheItemPoolInterface
    {
        return new ArrayAdapter();
    }
}

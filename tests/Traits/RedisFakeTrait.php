<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Traits;

class RedisFake extends \Redis
{
    private array $store  = [];
    private array $expiry = [];

    public function connect($host, $port = 6379, $timeout = 0, $reserved = null, $retry_interval = 0, $read_timeout = 0, $context = null): bool
    {
        return true;
    }

    public function setex($key, $ttl, $value): bool
    {
        $this->store[$key]  = $value;
        $this->expiry[$key] = time() + (int) $ttl;

        return true;
    }

    public function set($key, $value, $options = null): bool
    {
        $this->store[$key]  = $value;
        $this->expiry[$key] = null;

        return true;
    }

    public function get($key): mixed
    {
        if (!array_key_exists($key, $this->store)) {
            return false;
        }
        $exp = $this->expiry[$key] ?? null;
        if ($exp !== null && time() > $exp) {
            unset($this->store[$key], $this->expiry[$key]);

            return false;
        }

        return $this->store[$key];
    }

    public function del($key): int
    {
        if (isset($this->store[$key])) {
            unset($this->store[$key], $this->expiry[$key]);

            return 1;
        }

        return 0;
    }

    public function auth($password): bool
    {
        return true;
    }
}

trait RedisFakeTrait
{
    protected function redis(): \Redis
    {
        return new RedisFake();
    }
}

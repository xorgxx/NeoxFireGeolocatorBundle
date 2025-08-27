<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service;

use Neox\FireGeolocatorBundle\Service\Cache\CacheKey;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

class ExclusionManager
{
    public function __construct(private CacheItemPoolInterface $cache, private int $ttl)
    {
    }

    private function normalize(string $key): string
    {
        // PSR-6 allowed characters: A-Z a-z 0-9 _ .
        // Replace any other character by underscore to comply strictly
        return preg_replace('/[^A-Za-z0-9_.]/', '_', $key);
    }

    private function key(string $type, string $id): string
    {
        return $this->normalize(CacheKey::exc($type, $id));
    }

    /**
     * @throws InvalidArgumentException
     */
    public function isExcluded(string $type, string $id): bool
    {
        $item = $this->cache->getItem($this->key($type, $id));

        return $item->isHit() ? (bool) $item->get() : false;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function exclude(string $type, string $id, ?int $ttl = null): void
    {
        $item = $this->cache->getItem($this->key($type, $id));
        $item->set(true);
        if ($ttl !== null) {
            $item->expiresAfter($ttl);
        } else {
            $item->expiresAfter($this->ttl);
        }
        $this->cache->save($item);
    }

    public function clear(string $type, string $id): void
    {
        $this->cache->deleteItem($this->key($type, $id));
    }
}

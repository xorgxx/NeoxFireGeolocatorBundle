<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service;

use Neox\FireGeolocatorBundle\Service\Cache\StorageInterface;

class BanManager
{
    public function __construct(
        private StorageInterface $storage,
        private int $ttl = 3600,
        private int $maxAttempts = 10,
        private string $banDuration = '1 hour'
    ) {
    }

    public function increment(string $bucket): int
    {
        // Incrémente et décide immédiatement du bannissement si le seuil est atteint
        $attempts = $this->storage->incrementAttempts($bucket, $this->ttl);
        if ($attempts >= $this->maxAttempts) {
            $banUntilTs = strtotime('+' . $this->banDuration);
            $banUntilTs = $banUntilTs !== false ? $banUntilTs : (time() + $this->ttl);
            $ttl        = max(60, $banUntilTs - time());

            $this->storage->banIp($bucket, [
                'ip_hash'      => $bucket,
                'algo_version' => 'v1',
                'reason'       => 'rate_limit_exceeded',
                'banned_at'    => date('c'),
                'banned_until' => date('c', $banUntilTs),
                'attempts'     => $attempts,
            ], $ttl);

            // Éviter le re-ban immédiat à l'expiration
            $this->storage->resetAttempts($bucket);
        }

        return $attempts;
    }

    public function isBanned(string $bucket): bool
    {
        // Ne fait que vérifier l'état de ban actuel
        return $this->storage->isBanned($bucket);
    }

    public function clear(string $bucket): void
    {
        $this->storage->removeBan($bucket);
        $this->storage->resetAttempts($bucket);
    }
}

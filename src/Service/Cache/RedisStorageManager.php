<?php

namespace Neox\FireGeolocatorBundle\Service\Cache;

use Predis\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RedisStorageManager implements StorageInterface
{
    private const PREFIX_BAN      = 'geolocator:ban:';
    private const PREFIX_ATTEMPTS = 'geolocator:attempts:';
    private const KEY_BANNED_IPS  = 'geolocator:banned_ips';
    private const KEY_METADATA    = 'geolocator:metadata';

    public function __construct(
        private readonly Client $redis,
        private ?LoggerInterface $logger,
        private int $defaultGeoCtxTtl = 300
    ) {
        // Normaliser le logger pour éviter tout TypeError/Null deref
        $this->logger ??= new NullLogger();
    }

    // Méthodes génériques
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = $this->redis->get($key);
            if ($value === null) {
                return $default;
            }

            $decoded = json_decode($value, true);

            return $decoded !== null ? $decoded : $value;
        } catch (\Exception $e) {
            $this->logger->error('Redis GET error', ['key' => $key, 'error' => $e->getMessage()]);

            return $default;
        }
    }

    public function set(string $key, mixed $value): bool
    {
        try {
            $encodedValue = is_array($value) ? json_encode($value) : $value;
            $result       = $this->redis->set($key, $encodedValue);

            if ($result) {
                // Appliquer un TTL aux résultats async pour éviter une rétention illimitée
                if (str_starts_with($key, 'async_geo_result_')) {
                    // TTL de 600 secondes (10 minutes)
                    $this->redis->expire($key, 600);
                }
                // Appliquer un TTL aux contextes géo pour éviter une rétention illimitée
                if (str_starts_with($key, 'geo_ctx:')) {
                    $ttl = max(0, (int) $this->defaultGeoCtxTtl);
                    if ($ttl > 0) {
                        $this->redis->expire($key, $ttl);
                    }
                }
            }

            return $result === 'OK';
        } catch (\Exception $e) {
            $this->logger->error('Redis SET error', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function setWithTtl(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            $encodedValue = is_array($value) ? json_encode($value) : $value;
            if ($ttl !== null && $ttl > 0) {
                $result = $this->redis->setex($key, $ttl, $encodedValue);

                return $result === 'OK';
            }

            // Pas de TTL fourni: fallback sur set() existant (garde règles par défaut)
            return $this->set($key, $value);
        } catch (\Exception $e) {
            $this->logger->error('Redis setWithTtl error', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->redis->del($key);
            $this->redis->srem(self::KEY_BANNED_IPS, $key);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Redis DELETE error', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function exists(string $key): bool
    {
        try {
            return $this->redis->exists($key) > 0;
        } catch (\Exception $e) {
            $this->logger->error('Redis EXISTS error', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function clear(): bool
    {
        try {
            // Itérer avec SCAN pour éviter de bloquer Redis
            $cursor  = 0;
            $deleted = 0;
            do {
                $scan   = $this->redis->scan($cursor, ['match' => 'geolocator:*', 'count' => 500]);
                $cursor = $scan[0] ?? 0;
                $batch  = $scan[1] ?? [];
                if (!empty($batch)) {
                    $this->redis->del($batch);
                    $deleted += count($batch);
                }
            } while ($cursor !== 0 && $cursor !== '0');

            // Mettre à jour les métadonnées
            $this->redis->hset(self::KEY_METADATA, 'last_cleared', (new \DateTime())->format('c'));
            $this->redis->hset(self::KEY_METADATA, 'cleared_keys_count', (string) $deleted);

            $this->logger->info('Redis storage cleared successfully', ['deleted_keys' => $deleted]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Redis CLEAR error', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function getAll(): array
    {
        try {
            return [
                'bans'     => $this->getAllBanned(),
                'attempts' => $this->getAllAttempts(),
                'stats'    => $this->getStats(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Redis getAll error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function count(): int
    {
        try {
            return $this->redis->scard(self::KEY_BANNED_IPS);
        } catch (\Exception $e) {
            $this->logger->error('Redis COUNT error', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    // Méthodes spécifiques aux bannissements
    public function isBanned(string $ip): bool
    {
        try {
            return $this->redis->exists(self::PREFIX_BAN . $ip) > 0;
        } catch (\Exception $e) {
            $this->logger->error('Redis isBanned error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function getBanInfo(string $ip): ?array
    {
        try {
            $value = $this->redis->get(self::PREFIX_BAN . $ip);
            if ($value === null) {
                return null;
            }

            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : ['ip' => $ip, 'reason' => (string) $value];
        } catch (\Exception $e) {
            $this->logger->error('Redis getBanInfo error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function banIp(string $ip, array $banInfo, ?int $ttl = null): bool
    {
        try {
            $encodedValue = json_encode($banInfo);

            if ($ttl) {
                $this->redis->setex(self::PREFIX_BAN . $ip, $ttl, $encodedValue);
            } else {
                $this->redis->set(self::PREFIX_BAN . $ip, $encodedValue);
            }

            $this->redis->sadd(self::KEY_BANNED_IPS, [$ip]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Redis banIp error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function removeBan(string $ip): bool
    {
        try {
            $this->redis->del(self::PREFIX_BAN . $ip);
            $this->redis->srem(self::KEY_BANNED_IPS, $ip);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Redis removeBan error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function getAllBanned(): array
    {
        try {
            $ips    = $this->redis->smembers(self::KEY_BANNED_IPS);
            $result = [];

            foreach ($ips as $ip) {
                $banInfo = $this->getBanInfo($ip);
                if ($banInfo) {
                    $result[$ip] = $banInfo;
                } else {
                    // Nettoyer les références orphelines
                    $this->redis->srem(self::KEY_BANNED_IPS, $ip);
                }
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Redis getAllBanned error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function cleanExpiredBans(): int
    {
        try {
            $ips   = $this->redis->smembers(self::KEY_BANNED_IPS);
            $count = 0;

            foreach ($ips as $ip) {
                if (!$this->redis->exists(self::PREFIX_BAN . $ip)) {
                    $this->redis->srem(self::KEY_BANNED_IPS, $ip);
                    ++$count;
                }
            }

            // Nettoyer aussi les tentatives avec TTL expiré (iteration par SCAN)
            $cursor = 0;
            do {
                $scan   = $this->redis->scan($cursor, ['match' => self::PREFIX_ATTEMPTS . '*', 'count' => 500]);
                $cursor = $scan[0] ?? 0;
                $keys   = $scan[1] ?? [];
                foreach ($keys as $key) {
                    if (!$this->redis->exists($key)) {
                        ++$count;
                    }
                }
            } while ($cursor !== 0 && $cursor !== '0');

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Redis cleanExpiredBans error', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    // Méthodes spécifiques aux tentatives
    public function incrementAttempts(string $ip, int $ttl): int
    {
        try {
            $key      = self::PREFIX_ATTEMPTS . $ip;
            $attempts = $this->redis->incr($key);

            if ($attempts === 1) {
                // Premier increment, définir le TTL
                $this->redis->expire($key, $ttl);
            }

            return $attempts;
        } catch (\Exception $e) {
            $this->logger->error('Redis incrementAttempts error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    public function getAttempts(string $ip): int
    {
        try {
            $value = $this->redis->get(self::PREFIX_ATTEMPTS . $ip);

            return $value ? (int) $value : 0;
        } catch (\Exception $e) {
            $this->logger->error('Redis getAttempts error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    public function resetAttempts(string $ip): bool
    {
        try {
            $this->redis->del(self::PREFIX_ATTEMPTS . $ip);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Redis resetAttempts error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function getAttemptsTtl(string $ip): ?int
    {
        try {
            $ttl = $this->redis->ttl(self::PREFIX_ATTEMPTS . $ip);
            if ($ttl === false || $ttl === -2 || $ttl === -1) {
                return null; // no key or no expiry
            }

            return $ttl >= 0 ? $ttl : null;
        } catch (\Exception $e) {
            $this->logger->error('Redis getAttemptsTtl error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function getBanTtl(string $ip): ?int
    {
        try {
            $ttl = $this->redis->ttl(self::PREFIX_BAN . $ip);
            if ($ttl === false || $ttl === -2 || $ttl === -1) {
                return null; // no key or no expiry
            }

            return $ttl >= 0 ? $ttl : null;
        } catch (\Exception $e) {
            $this->logger->error('Redis getBanTtl error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // Méthodes statistiques
    public function getStats(): array
    {
        try {
            $info       = $this->redis->info();
            $memoryInfo = $this->redis->info('memory');
            $metadata   = $this->redis->hgetall(self::KEY_METADATA);

            return [
                'total_active_bans'     => $this->redis->scard(self::KEY_BANNED_IPS),
                'total_active_attempts' => $this->countActiveAttempts(),
                // Provide consistent keys across implementations
                'total_permanent_bans'    => null,
                'total_temporary_bans'    => null,
                'storage_type'            => 'redis',
                'redis_version'           => $info['redis_version']                ?? 'unknown',
                'redis_memory_used'       => $memoryInfo['used_memory_human']      ?? 'unknown',
                'redis_memory_peak'       => $memoryInfo['used_memory_peak_human'] ?? 'unknown',
                'redis_connected_clients' => $info['connected_clients']            ?? 'unknown',
                // Eviter KEYS: compter via SCAN
                'total_geolocator_keys' => (function (): int {
                    $cursor = 0;
                    $total  = 0;
                    do {
                        $scan   = $this->redis->scan($cursor, ['match' => 'geolocator:*', 'count' => 500]);
                        $cursor = $scan[0] ?? 0;
                        $batch  = $scan[1] ?? [];
                        $total += count($batch);
                    } while ($cursor !== 0 && $cursor !== '0');

                    return $total;
                })(),
                'last_cleared'       => $metadata['last_cleared']       ?? null,
                'last_cleanup'       => $metadata['last_cleared']       ?? null,
                'cleared_keys_count' => $metadata['cleared_keys_count'] ?? null,
                'uptime_seconds'     => $info['uptime_in_seconds']      ?? null,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Redis getStats error', ['error' => $e->getMessage()]);

            return [
                'storage_type' => 'redis',
                'error'        => 'Unable to retrieve stats: ' . $e->getMessage(),
            ];
        }
    }

    // Méthodes privées et utilitaires
    private function getAllAttempts(): array
    {
        try {
            $result = [];
            $cursor = 0;
            do {
                $scan   = $this->redis->scan($cursor, ['match' => self::PREFIX_ATTEMPTS . '*', 'count' => 500]);
                $cursor = $scan[0] ?? 0;
                $keys   = $scan[1] ?? [];
                foreach ($keys as $key) {
                    $ip       = str_replace(self::PREFIX_ATTEMPTS, '', $key);
                    $attempts = $this->redis->get($key);
                    $ttl      = $this->redis->ttl($key);

                    if ($attempts && $ttl > 0) {
                        $result[$ip] = [
                            'attempts'   => (int) $attempts,
                            'expires_in' => $ttl,
                            'expires_at' => (new \DateTime('+' . $ttl . ' seconds'))->format('c'),
                        ];
                    }
                }
            } while ($cursor !== 0 && $cursor !== '0');

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Redis getAllAttempts error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function countActiveAttempts(): int
    {
        try {
            $count  = 0;
            $cursor = 0;
            do {
                $scan   = $this->redis->scan($cursor, ['match' => self::PREFIX_ATTEMPTS . '*', 'count' => 500]);
                $cursor = $scan[0] ?? 0;
                $keys   = $scan[1] ?? [];
                foreach ($keys as $key) {
                    if ($this->redis->exists($key)) {
                        ++$count;
                    }
                }
            } while ($cursor !== 0 && $cursor !== '0');

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Redis countActiveAttempts error', ['error' => $e->getMessage()]);

            return 0;
        }
    }
}

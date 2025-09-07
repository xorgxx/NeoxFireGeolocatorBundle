<?php

namespace Neox\FireGeolocatorBundle\Service\Cache;

use Neox\FireGeolocatorBundle\Entity\IpBan;
use Neox\FireGeolocatorBundle\Repository\IpAttemptRepository;
use Neox\FireGeolocatorBundle\Repository\IpBanRepository;
use Psr\Log\LoggerInterface;

class DoctrineStorage implements StorageInterface
{
    public function __construct(
        private readonly IpBanRepository $banRepository,
        private readonly IpAttemptRepository $attemptRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, mixed>|mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $ban = $this->banRepository->findOneByIp($key);

            return $ban ? $this->entityToArray($ban) : $default;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine GET error', ['key' => $key, 'error' => $e->getMessage()]);

            return $default;
        }
    }

    public function set(string $key, mixed $value): bool
    {
        try {
            // interpret as a ban upsert
            $reason    = null;
            $expiresAt = null;
            if (is_array($value)) {
                $reason = $value['reason'] ?? null;
                if (isset($value['expiration'])) {
                    $exp       = $value['expiration'];
                    $expiresAt = is_string($exp) ? new \DateTimeImmutable($exp) : ($exp instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($exp) : null);
                }
                if (str_starts_with($key, 'async_geo_result_')) {
                    $expiresAt = (new \DateTimeImmutable('+10 minutes'));
                }
            } elseif (is_string($value)) {
                $reason = $value;
            }

            $this->banRepository->ban($key, $expiresAt, $reason);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine SET error', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function setWithTtl(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            if ($ttl !== null && $ttl > 0) {
                $reason    = null;
                $expiresAt = (new \DateTimeImmutable('now'))->modify('+' . $ttl . ' seconds');
                if (is_array($value)) {
                    $reason = $value['reason'] ?? null;
                } elseif (is_string($value)) {
                    $reason = $value;
                }
                $this->banRepository->ban($key, $expiresAt, $reason);

                return true;
            }

            // Pas de TTL fourni: fallback
            return $this->set($key, $value);
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine setWithTtl error', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->banRepository->unban($key);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine DELETE error', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function exists(string $key): bool
    {
        try {
            return $this->banRepository->isBanned($key);
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine EXISTS error', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $this->banRepository->createQueryBuilder('b')->delete()->getQuery()->execute();
            $this->attemptRepository->createQueryBuilder('a')->delete()->getQuery()->execute();
            $this->logger->info('Doctrine storage cleared successfully');

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine CLEAR error', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array{bans: array<string, array<string, mixed>>, attempts: array<string, array<string, mixed>>, stats: array<string, int|string|null>}
     */
    public function getAll(): array
    {
        try {
            return [
                'bans'     => $this->getAllBanned(),
                'attempts' => $this->getAllAttempts(),
                'stats'    => $this->getStats(),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine getAll error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function isBanned(string $ip): bool
    {
        try {
            return $this->banRepository->isBanned($ip);
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine isBanned error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBanInfo(string $ip): ?array
    {
        try {
            $ban = $this->banRepository->findOneByIp($ip);

            return $ban && $ban->isActive() ? $this->entityToArray($ban) : null;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine getBanInfo error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function banIp(string $ip, array $banInfo, ?int $ttl = null): bool
    {
        try {
            if ($ttl !== null) {
                $this->banRepository->banFor($ip, max(0, $ttl), $banInfo['reason'] ?? null, $banInfo['source'] ?? null);
            } else {
                $expiresAt = null;
                if (isset($banInfo['expiration'])) {
                    $exp       = $banInfo['expiration'];
                    $expiresAt = is_string($exp) ? new \DateTimeImmutable($exp) : ($exp instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($exp) : null);
                }
                $this->banRepository->ban($ip, $expiresAt, $banInfo['reason'] ?? null, $banInfo['source'] ?? null);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine banIp error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function removeBan(string $ip): bool
    {
        try {
            $this->banRepository->unban($ip);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine removeBan error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function incrementAttempts(string $ip, int $ttl): int
    {
        try {
            $attempt = $this->attemptRepository->increment($ip);

            return $attempt->getAttempts();
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine incrementAttempts error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    public function getAttempts(string $ip): int
    {
        try {
            $attempt = $this->attemptRepository->findOneByIp($ip);

            return $attempt ? $attempt->getAttempts() : 0;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine getAttempts error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    public function resetAttempts(string $ip): bool
    {
        try {
            $this->attemptRepository->reset($ip);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine resetAttempts error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function getAttemptsTtl(string $ip): ?int
    {
        // Attempts TTL is not tracked in current schema
        return null;
    }

    public function getBanTtl(string $ip): ?int
    {
        try {
            $ban = $this->banRepository->findOneByIp($ip);
            if (!$ban) {
                return null;
            }
            $expiresAt = $ban->getExpiresAt();
            if (!$expiresAt) {
                return null; // permanent ban has no TTL
            }
            $ttl = $expiresAt->getTimestamp() - time();

            return $ttl > 0 ? $ttl : null;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine getBanTtl error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAllBanned(): array
    {
        try {
            $bans   = $this->banRepository->findAll();
            $result = [];

            foreach ($bans as $ban) {
                if ($ban->isActive()) {
                    $result[$ban->getIp()] = $this->entityToArray($ban);
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine getAllBanned error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAllAttempts(): array
    {
        try {
            $attempts = $this->attemptRepository->findAll();
            $result   = [];

            foreach ($attempts as $attempt) {
                $result[$attempt->getIp()] = [
                    'ip'              => $attempt->getIp(),
                    'attempts'        => $attempt->getAttempts(),
                    'created_at'      => $attempt->getCreatedAt()->format('c'),
                    'updated_at'      => $attempt->getUpdatedAt()->format('c'),
                    'last_attempt_at' => $attempt->getLastAttemptAt()->format('c'),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine getAllAttempts error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function cleanExpiredBans(): int
    {
        try {
            $qb      = $this->banRepository->createQueryBuilder('b');
            $deleted = $qb->delete()
                ->where('b.expiresAt IS NOT NULL')
                ->andWhere('b.expiresAt <= :now')
                ->setParameter('now', new \DateTimeImmutable())
                ->getQuery()
                ->execute();
            $deleted = is_int($deleted) ? $deleted : (is_numeric($deleted) ? (int) $deleted : 0);

            return $deleted;
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine cleanExpiredBans error', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    public function count(): int
    {
        try {
            $qb = $this->banRepository->createQueryBuilder('b');

            return (int) $qb->select('COUNT(b.id)')
                ->where('b.expiresAt IS NULL OR b.expiresAt > :now')
                ->setParameter('now', new \DateTimeImmutable())
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine count error', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * @return array<string, int|string|null>
     */
    public function getStats(): array
    {
        try {
            return [
                'total_active_bans'     => $this->count(),
                'total_permanent_bans'  => $this->countPermanentBans(),
                'total_temporary_bans'  => $this->countTemporaryBans(),
                'total_active_attempts' => $this->countActiveAttempts(),
                'storage_type'          => 'doctrine',
                'last_cleanup'          => $this->getLastCleanupDate(),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Doctrine getStats error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    // Méthodes utilitaires privées

    /**
     * @return array<string, mixed>
     */
    private function entityToArray(IpBan $ban): array
    {
        return [
            'ip'         => $ban->getIp(),
            'reason'     => $ban->getReason(),
            'banned_at'  => $ban->getCreatedAt()->format('c'),
            'expiration' => $ban->getExpiresAt()?->format('c'),
        ];
    }

    private function countPermanentBans(): int
    {
        try {
            $qb = $this->banRepository->createQueryBuilder('b');

            return (int) $qb->select('COUNT(b.id)')
                ->where('b.expiresAt IS NULL')
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function countTemporaryBans(): int
    {
        try {
            $qb = $this->banRepository->createQueryBuilder('b');

            return (int) $qb->select('COUNT(b.id)')
                ->where('b.expiresAt IS NOT NULL')
                ->andWhere('b.expiresAt > :now')
                ->setParameter('now', new \DateTimeImmutable())
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function countActiveAttempts(): int
    {
        try {
            $qb = $this->attemptRepository->createQueryBuilder('a');

            return (int) $qb->select('COUNT(a.id)')
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return null
     */
    private function getLastCleanupDate()
    {
        // Not tracked in current implementation
        return null;
    }
}

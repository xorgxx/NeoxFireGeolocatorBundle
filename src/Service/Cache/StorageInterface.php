<?php

namespace Neox\FireGeolocatorBundle\Service\Cache;

interface StorageInterface
{
    // Méthodes génériques
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): bool;

    /**
     * Set a value with optional TTL (in seconds). If TTL is null, implementations should keep their default behavior.
     */
    public function setWithTtl(string $key, mixed $value, ?int $ttl = null): bool;

    public function delete(string $key): bool;

    public function exists(string $key): bool;

    public function clear(): bool;

    public function getAll(): array;

    public function count(): int;

    // Méthodes spécifiques aux bannissements
    public function isBanned(string $ip): bool;

    public function getBanInfo(string $ip): ?array;

    public function banIp(string $ip, array $banInfo, ?int $ttl = null): bool;

    public function removeBan(string $ip): bool;

    public function getAllBanned(): array;

    public function cleanExpiredBans(): int;

    // Méthodes spécifiques aux tentatives
    public function incrementAttempts(string $ip, int $ttl): int;

    public function getAttempts(string $ip): int;

    public function resetAttempts(string $ip): bool;

    // TTL helpers (seconds remaining) — return null if not applicable/unknown
    public function getAttemptsTtl(string $ip): ?int;

    public function getBanTtl(string $ip): ?int;

    // Méthodes statistiques
    public function getStats(): array;
}

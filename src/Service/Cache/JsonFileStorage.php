<?php

namespace Neox\FireGeolocatorBundle\Service\Cache;

use Psr\Log\LoggerInterface;

class JsonFileStorage implements StorageInterface
{
    /**
     * Normalize ban info schema to a unified shape.
     * Ensures keys: ip, reason, banned_at, expiration (optional, ISO8601) and preserves extra metadata.
     */
    private function normalizeBanInfo(string $ip, array $banInfo, ?int $ttl = null): array
    {
        $info = $banInfo;
        // Forcer la valeur de l'IP depuis le paramètre (source d'autorité) pour éviter tout accès à une clé manquante
        $info['ip']     = $ip;
        $info['reason'] = is_string($info['reason'] ?? '') && $info['reason'] !== '' ? $info['reason'] : 'manual';
        if (!isset($info['banned_at']) || !is_string($info['banned_at']) || $info['banned_at'] === '') {
            $info['banned_at'] = (new \DateTime())->format('c');
        } else {
            // validate date string
            try {
                new \DateTime($info['banned_at']);
            } catch (\Exception) {
                $info['banned_at'] = (new \DateTime())->format('c');
            }
        }
        // expiration handling: ttl wins if provided and no explicit expiration
        if ($ttl !== null && (!isset($info['expiration']) || !is_string($info['expiration']) || $info['expiration'] === '')) {
            try {
                $exp                = (new \DateTime())->add(new \DateInterval('PT' . max(0, (int) $ttl) . 'S'));
                $info['expiration'] = $exp->format('c');
            } catch (\Exception) {
                // ignore
            }
        } elseif (isset($info['expiration']) && is_string($info['expiration'])) {
            try {
                new \DateTime($info['expiration']);
            } catch (\Exception) {
                unset($info['expiration']);
            }
        }

        return $info;
    }
    private array $data  = [];
    private bool $loaded = false;

    public function __construct(
        private readonly string $filePath,
        private readonly LoggerInterface $logger
    ) {
    }

    // Méthodes génériques
    public function get(string $key, mixed $default = null): mixed
    {
        $this->loadData();

        return $this->data['bans'][$key] ?? $default;
    }

    public function set(string $key, mixed $value): bool
    {
        try {
            $this->loadData();
            // Pour les résultats async, ajouter une expiration (10 minutes)
            if (str_starts_with($key, 'async_geo_result_')) {
                if (is_array($value)) {
                    $value['expiration'] = (new \DateTime('+10 minutes'))->format('c');
                }
            }
            $this->data['bans'][$key] = $value;

            return $this->saveData();
        } catch (\Exception $e) {
            $this->logger->error('JSON SET error', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->loadData();
            unset($this->data['bans'][$key]);

            return $this->saveData();
        } catch (\Exception $e) {
            $this->logger->error('JSON DELETE error', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function exists(string $key): bool
    {
        $this->loadData();

        return isset($this->data['bans'][$key]);
    }

    public function clear(): bool
    {
        try {
            $this->data = [
                'bans'     => [],
                'attempts' => [],
                'metadata' => [
                    'last_cleared' => (new \DateTime())->format('c'),
                ],
            ];

            $result = $this->saveData();

            if ($result) {
                $this->logger->info('JSON storage cleared successfully');
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('JSON CLEAR error', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function getAll(): array
    {
        $this->loadData();

        return $this->data;
    }

    public function count(): int
    {
        $this->loadData();

        return count($this->data['bans'] ?? []);
    }

    // Méthodes spécifiques aux bannissements
    public function isBanned(string $ip): bool
    {
        $this->loadData();
        $banInfo = $this->data['bans'][$ip] ?? null;

        if (!$banInfo) {
            return false;
        }

        // Si bannissement permanent
        if (!isset($banInfo['expiration'])) {
            return true;
        }

        // Vérifier si le bannissement a expiré
        try {
            $expiration = new \DateTime($banInfo['expiration']);
            if ($expiration > new \DateTime()) {
                return true;
            }

            // Le bannissement a expiré, le supprimer
            $this->removeBan($ip);

            return false;
        } catch (\Exception $e) {
            $this->logger->error('Error checking ban expiration', [
                'ip'    => $ip,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getBanInfo(string $ip): ?array
    {
        $this->loadData();
        $banInfo = $this->data['bans'][$ip] ?? null;

        if (!$banInfo) {
            return null;
        }

        // Vérifier si le bannissement a expiré
        if (isset($banInfo['expiration'])) {
            try {
                $expiration = new \DateTime($banInfo['expiration']);
                if ($expiration <= new \DateTime()) {
                    $this->removeBan($ip);

                    return null;
                }
            } catch (\Exception $e) {
                $this->logger->error('Error parsing ban expiration', [
                    'ip'    => $ip,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        // Normaliser la forme retournée sans modifier le stockage
        $banInfo = $this->normalizeBanInfo($ip, $banInfo, null);

        return $banInfo;
    }

    public function banIp(string $ip, array $banInfo, ?int $ttl = null): bool
    {
        try {
            $this->loadData();

            // Normaliser le schéma de ban pour éviter les divergences
            $banInfo = $this->normalizeBanInfo($ip, $banInfo, $ttl);

            $this->data['bans'][$ip] = $banInfo;

            return $this->saveData();
        } catch (\Exception $e) {
            $this->logger->error('JSON banIp error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function removeBan(string $ip): bool
    {
        try {
            $this->loadData();
            if (isset($this->data['bans'][$ip])) {
                unset($this->data['bans'][$ip]);

                return $this->saveData();
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('JSON removeBan error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function getAllBanned(): array
    {
        $this->loadData();

        return $this->data['bans'] ?? [];
    }

    public function cleanExpiredBans(): int
    {
        $this->loadData();
        $count        = 0;
        $now          = new \DateTime();
        $bansToRemove = [];

        foreach ($this->data['bans'] ?? [] as $ip => $banInfo) {
            if (isset($banInfo['expiration'])) {
                try {
                    $expiration = new \DateTime($banInfo['expiration']);
                    if ($expiration <= $now) {
                        $bansToRemove[] = $ip;
                        ++$count;
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Invalid expiration date for IP', [
                        'ip'         => $ip,
                        'expiration' => $banInfo['expiration'],
                        'error'      => $e->getMessage(),
                    ]);
                    $bansToRemove[] = $ip;
                    ++$count;
                }
            }
        }

        // Supprimer les bannissements expirés
        foreach ($bansToRemove as $ip) {
            unset($this->data['bans'][$ip]);
        }

        // Nettoyer aussi les tentatives expirées
        $attemptsCleaned = $this->cleanExpiredAttempts();

        if ($count > 0 || $attemptsCleaned > 0) {
            $this->saveData();
        }

        return $count;
    }

    // Méthodes spécifiques aux tentatives
    public function incrementAttempts(string $ip, int $ttl): int
    {
        try {
            $this->loadData();
            $now        = new \DateTime();
            $expiration = (clone $now)->add(new \DateInterval('PT' . $ttl . 'S'));

            $attemptInfo = $this->data['attempts'][$ip] ?? null;

            // Vérifier si les tentatives existantes ont expiré
            if ($attemptInfo && isset($attemptInfo['expires_at'])) {
                try {
                    $expiresAt = new \DateTime($attemptInfo['expires_at']);
                    if ($expiresAt <= $now) {
                        $attemptInfo = null; // Expirées, recommencer
                    }
                } catch (\Exception $e) {
                    $attemptInfo = null; // Date invalide, recommencer
                }
            }

            if ($attemptInfo) {
                $attempts                    = $attemptInfo['count'] + 1;
                $this->data['attempts'][$ip] = [
                    'count'         => $attempts,
                    'last_attempt'  => $now->format('c'),
                    'expires_at'    => $attemptInfo['expires_at'], // Garder l'expiration originale
                    'first_attempt' => $attemptInfo['first_attempt'] ?? $now->format('c'),
                ];
            } else {
                $attempts                    = 1;
                $this->data['attempts'][$ip] = [
                    'count'         => $attempts,
                    'first_attempt' => $now->format('c'),
                    'last_attempt'  => $now->format('c'),
                    'expires_at'    => $expiration->format('c'),
                ];
            }

            $this->saveData();

            return $attempts;
        } catch (\Exception $e) {
            $this->logger->error('JSON incrementAttempts error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    public function getAttempts(string $ip): int
    {
        $this->loadData();
        $attemptInfo = $this->data['attempts'][$ip] ?? null;

        if (!$attemptInfo) {
            return 0;
        }

        // Vérifier si les tentatives ont expiré
        if (isset($attemptInfo['expires_at'])) {
            try {
                $expiresAt = new \DateTime($attemptInfo['expires_at']);
                if ($expiresAt <= new \DateTime()) {
                    unset($this->data['attempts'][$ip]);
                    $this->saveData();

                    return 0;
                }
            } catch (\Exception $e) {
                unset($this->data['attempts'][$ip]);
                $this->saveData();

                return 0;
            }
        }

        return $attemptInfo['count'] ?? 0;
    }

    public function resetAttempts(string $ip): bool
    {
        try {
            $this->loadData();
            unset($this->data['attempts'][$ip]);

            return $this->saveData();
        } catch (\Exception $e) {
            $this->logger->error('JSON resetAttempts error', ['ip' => $ip, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function getAttemptsTtl(string $ip): ?int
    {
        $this->loadData();
        $attemptInfo = $this->data['attempts'][$ip] ?? null;
        if (!$attemptInfo || !isset($attemptInfo['expires_at'])) {
            return null;
        }
        try {
            $expiresAt = new \DateTime($attemptInfo['expires_at']);
            $diff      = $expiresAt->getTimestamp() - time();
            if ($diff <= 0) {
                // clean expired
                unset($this->data['attempts'][$ip]);
                $this->saveData();

                return null;
            }

            return $diff;
        } catch (\Exception) {
            unset($this->data['attempts'][$ip]);
            $this->saveData();

            return null;
        }
    }

    public function getBanTtl(string $ip): ?int
    {
        $this->loadData();
        $banInfo = $this->data['bans'][$ip] ?? null;
        if (!$banInfo || !isset($banInfo['expiration'])) {
            return null; // no TTL (permanent or not found)
        }
        try {
            $expiration = new \DateTime($banInfo['expiration']);
            $diff       = $expiration->getTimestamp() - time();
            if ($diff <= 0) {
                // expired -> remove
                unset($this->data['bans'][$ip]);
                $this->saveData();

                return null;
            }

            return $diff;
        } catch (\Exception) {
            return null;
        }
    }

    // Méthodes statistiques
    public function getStats(): array
    {
        $this->loadData();

        $activeBans    = 0;
        $permanentBans = 0;
        $temporaryBans = 0;
        $now           = new \DateTime();

        foreach ($this->data['bans'] ?? [] as $banInfo) {
            if (!isset($banInfo['expiration'])) {
                ++$permanentBans;
                ++$activeBans;
            } else {
                try {
                    $expiration = new \DateTime($banInfo['expiration']);
                    if ($expiration > $now) {
                        ++$temporaryBans;
                        ++$activeBans;
                    }
                } catch (\Exception $e) {
                    // Date invalide, compter comme expiré
                }
            }
        }

        $activeAttempts = 0;
        foreach ($this->data['attempts'] ?? [] as $attemptInfo) {
            if (isset($attemptInfo['expires_at'])) {
                try {
                    $expiresAt = new \DateTime($attemptInfo['expires_at']);
                    if ($expiresAt > $now) {
                        ++$activeAttempts;
                    }
                } catch (\Exception $e) {
                    // Date invalide, ne pas compter
                }
            } else {
                ++$activeAttempts;
            }
        }

        $size = 0;
        if (file_exists($this->filePath)) {
            $fs   = filesize($this->filePath);
            $size = is_int($fs) ? $fs : 0;
        }
        $lastModified = null;
        if (file_exists($this->filePath)) {
            $mtime = filemtime($this->filePath);
            if (is_int($mtime)) {
                $lastModified = date('c', $mtime);
            }
        }

        return [
            'total_active_bans'     => $activeBans,
            'total_permanent_bans'  => $permanentBans,
            'total_temporary_bans'  => $temporaryBans,
            'total_active_attempts' => $activeAttempts,
            'storage_type'          => 'json',
            'file_path'             => $this->filePath,
            'file_size'             => $size,
            'file_readable'         => file_exists($this->filePath) && is_readable($this->filePath),
            'file_writable'         => is_writable(dirname($this->filePath)),
            'last_modified'         => $lastModified,
            'last_cleared'          => $this->data['metadata']['last_cleared'] ?? null,
            'last_cleanup'          => $this->data['metadata']['last_cleared'] ?? null,
        ];
    }

    // Méthodes privées
    private function loadData(): void
    {
        if ($this->loaded) {
            return;
        }

        if (!file_exists($this->filePath)) {
            $this->data = [
                'bans'     => [],
                'attempts' => [],
                'metadata' => [
                    'created_at' => (new \DateTime())->format('c'),
                ],
            ];
            $this->loaded = true;

            return;
        }

        try {
            $content = file_get_contents($this->filePath);
            if ($content === false) {
                throw new \RuntimeException('Unable to read file');
            }

            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
            }

            $decodedArr = is_array($decoded) ? $decoded : [];
            $this->data = $decodedArr;

            // S'assurer que les clés nécessaires existent et sont des tableaux
            if (!isset($this->data['bans']) || !is_array($this->data['bans'])) {
                $this->data['bans'] = [];
            }
            if (!isset($this->data['attempts']) || !is_array($this->data['attempts'])) {
                $this->data['attempts'] = [];
            }
            if (!isset($this->data['metadata']) || !is_array($this->data['metadata'])) {
                $this->data['metadata'] = [];
            }

            $this->loaded = true;
        } catch (\Exception $e) {
            $this->logger->error('Erreur chargement données JSON', [
                'file'  => $this->filePath,
                'error' => $e->getMessage(),
            ]);
            $this->data   = ['bans' => [], 'attempts' => [], 'metadata' => []];
            $this->loaded = true;
        }
    }

    private function saveData(): bool
    {
        try {
            $dir = dirname($this->filePath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0o755, true)) {
                    throw new \RuntimeException('Unable to create directory: ' . $dir);
                }
            }

            // Ajouter metadata de sauvegarde
            $this->data['metadata']['last_saved'] = (new \DateTime())->format('c');

            $content = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($content === false) {
                throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
            }

            $result = file_put_contents($this->filePath, $content, LOCK_EX);
            if ($result === false) {
                throw new \RuntimeException('Unable to write file');
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Erreur sauvegarde données JSON', [
                'file'  => $this->filePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function cleanExpiredAttempts(): int
    {
        $count            = 0;
        $now              = new \DateTime();
        $attemptsToRemove = [];

        foreach ($this->data['attempts'] ?? [] as $ip => $attemptInfo) {
            if (isset($attemptInfo['expires_at'])) {
                try {
                    $expiresAt = new \DateTime($attemptInfo['expires_at']);
                    if ($expiresAt <= $now) {
                        $attemptsToRemove[] = $ip;
                        ++$count;
                    }
                } catch (\Exception $e) {
                    $attemptsToRemove[] = $ip;
                    ++$count;
                }
            }
        }

        foreach ($attemptsToRemove as $ip) {
            unset($this->data['attempts'][$ip]);
        }

        return $count;
    }
}

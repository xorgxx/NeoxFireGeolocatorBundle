<?php

namespace Neox\FireGeolocatorBundle\Service\Cache;

use Doctrine\ORM\EntityManagerInterface;
use Neox\FireGeolocatorBundle\Entity\IpAttempt;
use Neox\FireGeolocatorBundle\Entity\IpBan;
use Neox\FireGeolocatorBundle\Repository\IpAttemptRepository;
use Neox\FireGeolocatorBundle\Repository\IpBanRepository;
use Predis\Client;
use Psr\Log\LoggerInterface;

class StorageFactory
{
    public function createStorage(array $config, ?EntityManagerInterface $connection = null, ?LoggerInterface $logger = null): DoctrineStorage|JsonFileStorage|RedisStorageManager
    {
        $dsn    = $config['storage']['dsn'];
        $scheme = $this->getSchemeFromDsn($config);

        return match (strtolower($scheme)) {
            'file', 'json' => $this->createJsonStorage($dsn, $logger),
            'redis' => $this->createRedisStorage($dsn, $logger),
            'doctrine', 'sqlite', 'mysql', 'postgresql', 'pgsql' => $this->createDoctrineStorage($dsn, $connection, $logger),
            default => throw new \InvalidArgumentException("Unsupported storage scheme: {$scheme} in DSN: {$dsn}")
        };
    }

    public function create(array $config, ?EntityManagerInterface $connection = null, ?LoggerInterface $logger = null): DoctrineStorage|JsonFileStorage|RedisStorageManager
    {
        return $this->createStorage($config, $connection, $logger);
    }

    private function getSchemeFromDsn(array $config): string
    {
        $dsn    = $config['storage']['dsn'];
        $parsed = parse_url($dsn);
        if ($parsed === false || !isset($parsed['scheme'])) {
            throw new \InvalidArgumentException("Invalid DSN format: {$dsn}");
        }

        return $parsed['scheme'];
    }

    private function createJsonStorage(string $dsn, ?LoggerInterface $logger): JsonFileStorage
    {
        $parsed   = parse_url($dsn);
        $filePath = $parsed['path'] ?? $dsn;

        return new JsonFileStorage($filePath, $logger);
    }

    private function createRedisStorage(string $dsn, ?LoggerInterface $logger): RedisStorageManager
    {
        //        $dsn = $config["storage"]["dsn"];
        if (!class_exists(Client::class)) {
            throw new \RuntimeException('Predis\Client class not found. Please install predis/predis package');
        }

        $redis = new Client($dsn);

        // Valider immédiatement la connexion Redis pour détecter les DSN/serveurs invalides
        try {
            // ping force la connexion lazy de Predis et lève une exception en cas d’échec
            $redis->ping();
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Unable to connect to Redis using DSN "%s": %s', $dsn, $e->getMessage()), previous: $e);
        }

        return new RedisStorageManager($redis, $logger);
    }

    private function createDoctrineStorage(string $dsn, ?EntityManagerInterface $em, ?LoggerInterface $logger): DoctrineStorage
    {
        if (!$em) {
            throw new \InvalidArgumentException('Doctrine EntityManager is required for Doctrine storage');
        }

        $banRepo     = $em->getRepository(IpBan::class);
        $attemptRepo = $em->getRepository(IpAttempt::class);
        if (!$banRepo instanceof IpBanRepository || !$attemptRepo instanceof IpAttemptRepository) {
            throw new \RuntimeException('Unexpected repository types for geolocator entities');
        }

        return new DoctrineStorage($banRepo, $attemptRepo, $logger);
    }
}

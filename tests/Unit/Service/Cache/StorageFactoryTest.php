<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service\Cache;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Neox\FireGeolocatorBundle\Entity\IpAttempt;
use Neox\FireGeolocatorBundle\Entity\IpBan;
use Neox\FireGeolocatorBundle\Repository\IpAttemptRepository;
use Neox\FireGeolocatorBundle\Repository\IpBanRepository;
use Neox\FireGeolocatorBundle\Service\Cache\JsonFileStorage;
use Neox\FireGeolocatorBundle\Service\Cache\StorageFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class StorageFactoryTest extends TestCase
{
    //    public function test_create_json_storage_from_file_scheme(): void
    //    {
    //        $factory = new StorageFactory();
    //        $storage = $factory->create(['storage' => ['dsn' => 'json:///tmp/test.json']], null, new NullLogger());
    //        $this->assertInstanceOf(JsonFileStorage::class, $storage);
    //    }

    public function testCreateJsonStorageFromFileSchemeShort(): void
    {
        $factory = new StorageFactory();
        $storage = $factory->create(['storage' => ['dsn' => 'file:///tmp/test.json']], null, new NullLogger());
        $this->assertInstanceOf(JsonFileStorage::class, $storage);
    }

    public function testRedisRequiresPredis(): void
    {
        $this->expectException(\RuntimeException::class);
        $factory = new StorageFactory();
        $factory->create(['storage' => ['dsn' => 'redis://localhost:6379']], null, new NullLogger());
    }

    public function testDoctrineRequiresEntityManager(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $factory = new StorageFactory();
        $factory->create(['storage' => ['dsn' => 'doctrine://bans']], null, new NullLogger());
    }

    public function testDoctrineWithEmPasses(): void
    {
        $factory = new StorageFactory();

        $em = $this->createMock(EntityManagerInterface::class);

        $banRepo     = $this->createMock(IpBanRepository::class);
        $attemptRepo = $this->createMock(IpAttemptRepository::class);

        $em->method('getRepository')
            ->willReturnCallback(function (string $class) use ($banRepo, $attemptRepo) {
                return match ($class) {
                    IpBan::class     => $banRepo,
                    IpAttempt::class => $attemptRepo,
                    default          => $this->createMock(ObjectRepository::class),
                };
            });

        $storage = $factory->create(['storage' => ['dsn' => 'doctrine://bans']], $em, new NullLogger());
        $this->assertNotNull($storage);
    }

    public function testInvalidSchemeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $factory = new StorageFactory();
        $factory->create(['storage' => ['dsn' => 'unsupported://foo']], null, new NullLogger());
    }
}

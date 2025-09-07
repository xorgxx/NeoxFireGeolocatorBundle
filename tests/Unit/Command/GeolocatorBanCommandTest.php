<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Command;

use Neox\FireGeolocatorBundle\Command\GeolocatorBanCommand;
use Neox\FireGeolocatorBundle\Service\Cache\StorageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class GeolocatorBanCommandTest extends TestCase
{
    private function command(StorageInterface $storage, array $config = []): GeolocatorBanCommand
    {
        $defaults = ['bans' => ['ttl' => 3600]];

        return new GeolocatorBanCommand($storage, $config + $defaults);
    }

    public function testAddRequiresSubject(): void
    {
        $storage = new class implements StorageInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                return null;
            }

            public function set(string $key, mixed $value): bool
            {
                return true;
            }

            public function setWithTtl(string $key, mixed $value, ?int $ttl = null): bool
            {
                return $this->set($key, $value);
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function clear(): bool
            {
                return true;
            }

            public function getAll(): array
            {
                return [];
            }

            public function count(): int
            {
                return 0;
            }

            public function isBanned(string $ip): bool
            {
                return false;
            }

            public function getBanInfo(string $ip): ?array
            {
                return null;
            }

            public function banIp(string $ip, array $banInfo, ?int $ttl = null): bool
            {
                return true;
            }

            public function removeBan(string $ip): bool
            {
                return true;
            }

            public function getAllBanned(): array
            {
                return [];
            }

            public function cleanExpiredBans(): int
            {
                return 0;
            }

            public function incrementAttempts(string $ip, int $ttl): int
            {
                return 0;
            }

            public function getAttempts(string $ip): int
            {
                return 0;
            }

            public function resetAttempts(string $ip): bool
            {
                return true;
            }

            public function getAttemptsTtl(string $ip): ?int
            {
                return null;
            }

            public function getBanTtl(string $ip): ?int
            {
                return null;
            }

            public function getStats(): array
            {
                return [];
            }
        };

        $app = new Application();
        $app->add($this->command($storage));
        $cmd    = $app->find('neox:firegeolocator:ban');
        $tester = new CommandTester($cmd);
        $status = $tester->execute(['action' => 'add']);
        $this->assertSame(2, $status, 'Missing subject should be INVALID');
        $this->assertStringContainsString('Sujet manquant', $tester->getDisplay());
    }

    public function testAddSuccessWithTtlSeconds(): void
    {
        $called  = [];
        $storage = new class($called) implements StorageInterface {
            public array $called;

            public function __construct(&$called)
            {
                $this->called = &$called;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return null;
            }

            public function set(string $key, mixed $value): bool
            {
                return true;
            }

            public function setWithTtl(string $key, mixed $value, ?int $ttl = null): bool
            {
                return $this->set($key, $value);
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function clear(): bool
            {
                return true;
            }

            public function getAll(): array
            {
                return [];
            }

            public function count(): int
            {
                return 0;
            }

            public function isBanned(string $ip): bool
            {
                return true;
            }

            public function getBanInfo(string $ip): ?array
            {
                return ['ip' => $ip];
            }

            public function banIp(string $ip, array $banInfo, ?int $ttl = null): bool
            {
                $this->called = [$ip, $banInfo, $ttl];

                return true;
            }

            public function removeBan(string $ip): bool
            {
                return true;
            }

            public function getAllBanned(): array
            {
                return [];
            }

            public function cleanExpiredBans(): int
            {
                return 0;
            }

            public function incrementAttempts(string $ip, int $ttl): int
            {
                return 0;
            }

            public function getAttempts(string $ip): int
            {
                return 0;
            }

            public function resetAttempts(string $ip): bool
            {
                return true;
            }

            public function getAttemptsTtl(string $ip): ?int
            {
                return null;
            }

            public function getBanTtl(string $ip): ?int
            {
                return null;
            }

            public function getStats(): array
            {
                return [];
            }
        };

        $app = new Application();
        $app->add($this->command($storage));
        $cmd    = $app->find('neox:firegeolocator:ban');
        $tester = new CommandTester($cmd);
        $status = $tester->execute(['action' => 'add', 'subject' => '1.2.3.4', '--ttl' => '120']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Banni ip-1.2.3.4 (ttl: 120s).', $tester->getDisplay());
    }

    public function testAttemptsIncrementAndResetAndStatus(): void
    {
        $state = [
            'attempts'    => 0,
            'banned'      => false,
            'banInfo'     => null,
            'banTtl'      => null,
            'attemptsTtl' => 30,
        ];
        $storage = new class($state) implements StorageInterface {
            private array $state;

            public function __construct(array &$state)
            {
                $this->state = &$state;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return null;
            }

            public function set(string $key, mixed $value): bool
            {
                return true;
            }

            public function setWithTtl(string $key, mixed $value, ?int $ttl = null): bool
            {
                return $this->set($key, $value);
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function clear(): bool
            {
                return true;
            }

            public function getAll(): array
            {
                return [];
            }

            public function count(): int
            {
                return 0;
            }

            public function isBanned(string $ip): bool
            {
                return $this->state['banned'];
            }

            public function getBanInfo(string $ip): ?array
            {
                return $this->state['banInfo'];
            }

            public function banIp(string $ip, array $banInfo, ?int $ttl = null): bool
            {
                $this->state['banned']  = true;
                $this->state['banInfo'] = $banInfo;
                $this->state['banTtl']  = $ttl;

                return true;
            }

            public function removeBan(string $ip): bool
            {
                $this->state['banned'] = false;

                return true;
            }

            public function getAllBanned(): array
            {
                return [];
            }

            public function cleanExpiredBans(): int
            {
                return 0;
            }

            public function incrementAttempts(string $ip, int $ttl): int
            {
                ++$this->state['attempts'];
                $this->state['attemptsTtl'] = $ttl;

                return $this->state['attempts'];
            }

            public function getAttempts(string $ip): int
            {
                return $this->state['attempts'];
            }

            public function resetAttempts(string $ip): bool
            {
                $this->state['attempts'] = 0;

                return true;
            }

            public function getAttemptsTtl(string $ip): ?int
            {
                return $this->state['attemptsTtl'];
            }

            public function getBanTtl(string $ip): ?int
            {
                return $this->state['banTtl'];
            }

            public function getStats(): array
            {
                return [];
            }
        };

        $app = new Application();
        $app->add($this->command($storage));
        $cmd    = $app->find('neox:firegeolocator:ban');
        $tester = new CommandTester($cmd);

        // Increment attempts +3
        $status = $tester->execute(['action' => 'attempts', 'subject' => '1.2.3.4', '--incr' => '3', '--ttl' => '45']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Tentatives incrémentées (+3). Total actuel: 3 (ttl base: 45s)', $tester->getDisplay());
        $this->assertStringContainsString(' - Attempts: 3 (ttl: 30s)', $tester->getDisplay()); // 30 from initial state

        // Reset attempts
        $status = $tester->execute(['action' => 'attempts', 'subject' => '1.2.3.4', '--reset' => true]);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Tentatives réinitialisées', $tester->getDisplay());
        $this->assertStringContainsString(' - Attempts: 0', $tester->getDisplay());
    }

    public function testListAndStatsAndClearExpired(): void
    {
        $storage = new class implements StorageInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                return null;
            }

            public function set(string $key, mixed $value): bool
            {
                return true;
            }

            public function setWithTtl(string $key, mixed $value, ?int $ttl = null): bool
            {
                return $this->set($key, $value);
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function clear(): bool
            {
                return true;
            }

            public function getAll(): array
            {
                return [];
            }

            public function count(): int
            {
                return 0;
            }

            public function isBanned(string $ip): bool
            {
                return true;
            }

            public function getBanInfo(string $ip): ?array
            {
                return ['ip' => $ip];
            }

            public function banIp(string $ip, array $banInfo, ?int $ttl = null): bool
            {
                return true;
            }

            public function removeBan(string $ip): bool
            {
                return true;
            }

            public function getAllBanned(): array
            {
                return ['ip-1.2.3.4' => ['reason' => 'manual']];
            }

            public function cleanExpiredBans(): int
            {
                return 2;
            }

            public function incrementAttempts(string $ip, int $ttl): int
            {
                return 0;
            }

            public function getAttempts(string $ip): int
            {
                return 0;
            }

            public function resetAttempts(string $ip): bool
            {
                return true;
            }

            public function getAttemptsTtl(string $ip): ?int
            {
                return null;
            }

            public function getBanTtl(string $ip): ?int
            {
                return 120;
            }

            public function getStats(): array
            {
                return ['total_active_bans' => 1];
            }
        };

        $app = new Application();
        $app->add($this->command($storage));
        $cmd    = $app->find('neox:firegeolocator:ban');
        $tester = new CommandTester($cmd);

        $status = $tester->execute(['action' => 'list']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Bans actifs:', $tester->getDisplay());

        $status = $tester->execute(['action' => 'stats']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Geolocator Storage Stats', $tester->getDisplay());

        $status = $tester->execute(['action' => 'clear-expired']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Nettoyé 2 bans expirés.', $tester->getDisplay());
    }
}

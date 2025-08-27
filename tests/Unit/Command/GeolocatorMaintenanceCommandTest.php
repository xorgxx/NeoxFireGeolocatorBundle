<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Command;

use Neox\FireGeolocatorBundle\Command\GeolocatorMaintenanceCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

// use Neox\FireGeolocatorBundle\Command\MaintenanceCommand;

final class GeolocatorMaintenanceCommandTest extends TestCase
{
    private function makeCommand(): array
    {
        $cache = new ArrayAdapter();
        $app   = new Application();
        $cmd   = new GeolocatorMaintenanceCommand($cache);
        $app->add($cmd);
        $command = $app->find('neox:firegeolocator:maintenance');

        return [$cache, $command];
    }

    public function testEnableWithTtlSetsFlagAndExpiration(): void
    {
        [$cache, $command] = $this->makeCommand();
        $tester            = new CommandTester($command);

        $exit = $tester->execute([
            'action'    => 'enable',
            '--ttl'     => 60,
            '--comment' => 'Maintenance rapide',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Maintenance activée', $tester->getDisplay());

        $item = $cache->getItem('neox_fire_geolocator_maintenance_flag');
        $this->assertTrue($item->isHit(), 'Le drapeau de maintenance doit être présent en cache');
        $payload = $item->get();
        $this->assertIsArray($payload);
        $this->assertTrue($payload['enabled'] ?? false);
        $this->assertSame(60, $payload['ttl']);
        $this->assertSame('Maintenance rapide', $payload['comment']);
        $this->assertNotEmpty($payload['since'] ?? '');
        $this->assertNotEmpty($payload['until'] ?? '');
    }

    public function testEnableWithDurationParsesHumanReadable(): void
    {
        [$cache, $command] = $this->makeCommand();
        $tester            = new CommandTester($command);

        $exit = $tester->execute([
            'action'     => 'enable',
            '--duration' => '1 hour',
        ]);

        $this->assertSame(0, $exit);
        $item = $cache->getItem('neox_fire_geolocator_maintenance_flag');
        $this->assertTrue($item->isHit());
        $payload = $item->get();
        $this->assertIsArray($payload);
        // ttl doit être > 0 et ~ 3600s, on vérifie juste > 3000 pour robustesse
        $this->assertGreaterThan(3000, (int) ($payload['ttl'] ?? 0));
        $this->assertNotEmpty($payload['until'] ?? '');
    }

    public function testStatusWhenDisabled(): void
    {
        [, $command] = $this->makeCommand();
        $tester      = new CommandTester($command);

        $exit = $tester->execute([
            'action' => 'status',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Maintenance: désactivée', $tester->getDisplay());
    }

    public function testDisableClearsFlag(): void
    {
        [$cache, $command] = $this->makeCommand();
        $tester            = new CommandTester($command);

        // Activer d'abord
        $this->assertSame(0, $tester->execute(['action' => 'enable', '--ttl' => 10]));
        $this->assertTrue($cache->getItem('neox_fire_geolocator_maintenance_flag')->isHit());

        // Puis désactiver
        $this->assertSame(0, $tester->execute(['action' => 'disable']));
        $this->assertFalse($cache->getItem('neox_fire_geolocator_maintenance_flag')->isHit());
        $this->assertStringContainsString('Maintenance désactivée.', $tester->getDisplay());
    }

    public function testEnableWithCommentPersistsComment(): void
    {
        [$cache, $command] = $this->makeCommand();
        $tester            = new CommandTester($command);

        $comment = 'Upgrade base de données';
        $this->assertSame(0, $tester->execute([
            'action'    => 'enable',
            '--ttl'     => 120,
            '--comment' => $comment,
        ]));

        $item    = $cache->getItem('neox_fire_geolocator_maintenance_flag');
        $payload = $item->get();
        $this->assertSame($comment, $payload['comment']);
        $this->assertStringContainsString('Commentaire:', $tester->getDisplay());
    }

    public function testInvalidActionReturnsErrorCode(): void
    {
        [, $command] = $this->makeCommand();
        $tester      = new CommandTester($command);

        $exit = $tester->execute([
            'action' => 'unknown',
        ]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Action invalide', $tester->getDisplay());
    }
}

<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Integration\Smoke;

use PHPUnit\Framework\TestCase;

final class IntegrationSmokeTest extends TestCase
{
    public function testPhpunitDetectsIntegrationSuite(): void
    {
        self::assertTrue(true, 'Integration suite is wired and running.');
    }
}

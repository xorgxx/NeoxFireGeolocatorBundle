<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\DependencyInjection;

use Neox\FireGeolocatorBundle\DependencyInjection\Compiler\FilterPriorityCompilerPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class FilterPriorityCompilerPassTest extends TestCase
{
    private function runPass(ContainerBuilder $cb): void
    {
        (new FilterPriorityCompilerPass())->process($cb);
    }

    public function testRewritesPriorityWhenConfigured(): void
    {
        $cb = new ContainerBuilder();
        $cb->setParameter('geolocator.filters_priority', ['vpn' => 300]);

        $def = new Definition('Xorgxx\\GeolocatorBundle\\Service\\Filter\\Core\\VpnFilter');
        $def->addTag('xorgxx.geolocator.filter', ['priority' => 5]);
        $cb->setDefinition('geolocator.vpn_filter', $def);

        $this->runPass($cb);

        $tags = $cb->getDefinition('geolocator.vpn_filter')->getTag('xorgxx.geolocator.filter');
        self::assertCount(1, $tags);
        self::assertSame(300, $tags[0]['priority']);
    }

    public function testKeepsPriorityWhenNotConfigured(): void
    {
        $cb = new ContainerBuilder();
        $cb->setParameter('geolocator.filters_priority', ['navigator' => 200]); // vpn not present

        $def = new Definition('Xorgxx\\GeolocatorBundle\\Service\\Filter\\Core\\VpnFilter');
        $def->addTag('xorgxx.geolocator.filter', ['priority' => 5]);
        $cb->setDefinition('geolocator.vpn_filter', $def);

        $this->runPass($cb);

        $tags = $cb->getDefinition('geolocator.vpn_filter')->getTag('xorgxx.geolocator.filter');
        self::assertCount(1, $tags);
        self::assertSame(5, $tags[0]['priority']);
    }

    public function testUsesTagCodeAttributeWhenPresent(): void
    {
        $cb = new ContainerBuilder();
        $cb->setParameter('geolocator.filters_priority', ['vpn' => 250]);

        // Class name that doesn't end with Filter
        $def = new Definition('App\\Foo\\BarBaz');
        $def->addTag('xorgxx.geolocator.filter', ['priority' => 1, 'code' => 'vpn']);
        $cb->setDefinition('app.custom_service', $def);

        $this->runPass($cb);

        $tags = $cb->getDefinition('app.custom_service')->getTag('xorgxx.geolocator.filter');
        self::assertCount(1, $tags);
        self::assertSame(250, $tags[0]['priority']);
    }
}

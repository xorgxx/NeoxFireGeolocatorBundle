<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\Service\Filter\FilterChain;
use Neox\FireGeolocatorBundle\Service\Filter\FilterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpFoundation\Request;

final class FilterOrderIntegrationTest extends TestCase
{
    public function testFiltersAreExecutedInDescendingPriorityOrder(): void
    {
        // Define two stub filters inside the test namespace so they are autoloadable
        $cb = new ContainerBuilder();
        $cb->setParameter('kernel.debug', false);

        // Register A and B filters
        $defA = new Definition(AFilter::class);
        $defA->addTag('xorgxx.geolocator.filter', ['priority' => 100]);
        $cb->setDefinition(AFilter::class, $defA);

        $defB = new Definition(BFilter::class);
        $defB->addTag('xorgxx.geolocator.filter', ['priority' => 200]);
        $cb->setDefinition(BFilter::class, $defB);

        // FilterChain with tagged iterator argument
        $chainDef = new Definition(FilterChain::class);
        $chainDef->setPublic(true);
        $chainDef->setArguments([new TaggedIteratorArgument('xorgxx.geolocator.filter')]);
        $cb->setDefinition('test.filter_chain', $chainDef);

        $cb->compile();

        // Reset static recorder
        CallRecorder::$calls = [];

        /** @var FilterChain $chain */
        $chain = $cb->get('test.filter_chain');
        $chain->decide(new Request(), new GeoApiContextDTO('1.2.3.4'));

        self::assertSame(['B', 'A'], CallRecorder::$calls, 'Expected B to run before A due to higher priority.');
    }
}

final class CallRecorder
{
    public static array $calls = [];
}

final class AFilter implements FilterInterface
{
    public function isEnabled(): bool
    {
        return true;
    }

    public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
    {
        CallRecorder::$calls[] = 'A';

        // Return allow to keep chain going, but not stop on denial
        return null;
    }
}

final class BFilter implements FilterInterface
{
    public function isEnabled(): bool
    {
        return true;
    }

    public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
    {
        CallRecorder::$calls[] = 'B';

        return null;
    }
}

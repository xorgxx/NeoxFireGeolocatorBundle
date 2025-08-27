<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service;

use Neox\FireGeolocatorBundle\Service\ExclusionManager;
use Neox\FireGeolocatorBundle\Tests\Traits\ArrayPsr6PoolTrait;
use PHPUnit\Framework\TestCase;

class ExclusionManagerTest extends TestCase
{
    use ArrayPsr6PoolTrait;

    public function testIsExcludedFalseByDefault(): void
    {
        $ex = new ExclusionManager($this->arrayPool(), ttl: 60);
        $this->assertFalse($ex->isExcluded('key', 'abc'));
    }

    public function testExcludeWithCustomTtlSetsFlag(): void
    {
        $pool = $this->arrayPool();
        $ex   = new ExclusionManager($pool, ttl: 60);
        $ex->exclude('key', 'abc', 5);
        $this->assertTrue($ex->isExcluded('key', 'abc'));
    }

    public function testExcludeWithDefaultTtlSetsFlag(): void
    {
        $pool = $this->arrayPool();
        $ex   = new ExclusionManager($pool, ttl: 120);
        // No TTL provided -> use default TTL from constructor
        $ex->exclude('key', 'with-default');
        $this->assertTrue($ex->isExcluded('key', 'with-default'));
    }

    public function testExcludeIsIdempotent(): void
    {
        $pool = $this->arrayPool();
        $ex   = new ExclusionManager($pool, ttl: 60);
        $ex->exclude('key', 'same', 60);
        $ex->exclude('key', 'same', 30); // different TTL provided, should still be excluded
        $this->assertTrue($ex->isExcluded('key', 'same'));
    }

    public function testClearOnNonexistentIsSafe(): void
    {
        $pool = $this->arrayPool();
        $ex   = new ExclusionManager($pool, ttl: 60);
        // clearing a non-existent key should not throw and should remain non-excluded
        $ex->clear('key', 'ghost');
        $this->assertFalse($ex->isExcluded('key', 'ghost'));
    }

    public function testTypeNamespaceIsolated(): void
    {
        $pool = $this->arrayPool();
        $ex   = new ExclusionManager($pool, ttl: 60);
        $ex->exclude('ip', '123', 60);
        $this->assertTrue($ex->isExcluded('ip', '123'));
        // same id but different type should not be marked excluded
        $this->assertFalse($ex->isExcluded('session', '123'));
    }

    public function testKeyNormalizationCollidesOnEquivalentForms(): void
    {
        $pool = $this->arrayPool();
        $ex   = new ExclusionManager($pool, ttl: 60);

        // inputs with disallowed chars normalize to the same PSR-6 key
        $typeOriginal = 'T Y-P/E';
        $idOriginal   = 'A B-C:D';
        $ex->exclude($typeOriginal, $idOriginal, 60);

        // These variants should normalize to the same cache key
        $typeVariant = 'T Y P_E'; // space and underscore where hyphen/slash were
        $idVariant   = 'A_B_C_D';

        // Should be considered excluded due to normalization collision
        $this->assertTrue($ex->isExcluded($typeOriginal, $idOriginal));
        $this->assertTrue($ex->isExcluded($typeVariant, $idVariant));
    }

    public function testExcludeAndClear(): void
    {
        $pool = $this->arrayPool();
        $ex   = new ExclusionManager($pool, ttl: 60);
        $this->assertFalse($ex->isExcluded('key', 'abc'));
        $ex->exclude('key', 'abc', 60);
        $this->assertTrue($ex->isExcluded('key', 'abc'));
        $ex->clear('key', 'abc');
        $this->assertFalse($ex->isExcluded('key', 'abc'));
    }
}

<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Service\Security;

use Neox\FireGeolocatorBundle\Service\Log\GeolocatorLoggerInterface;
use Neox\FireGeolocatorBundle\Service\Security\RateLimiterGuard;
use PHPUnit\Framework\TestCase;

final class RateLimiterGuardTest extends TestCase
{
    public function testAllowUsesCallableFactoryLimiter(): void
    {
        // Limiter that flips acceptance on each consume()
        $makeLimiter = function () {
            return new class {
                private bool $flip = true;

                public function consume(int $tokens)
                {
                    $accepted   = $this->flip;
                    $this->flip = !$this->flip;

                    return new class($accepted) {
                        public function __construct(private bool $accepted)
                        {
                        }

                        public function isAccepted(): bool
                        {
                            return $this->accepted;
                        }
                    };
                }
            };
        };

        // Callable factory that returns a stable limiter per bucket key
        $limiters = [];
        $factory  = function (string $key) use (&$limiters, $makeLimiter) {
            if (!isset($limiters[$key])) {
                $limiters[$key] = $makeLimiter();
            }

            return $limiters[$key];
        };

        $guard  = new RateLimiterGuard($factory);
        $bucket = 'rate:v1:abcd';

        self::assertTrue($guard->allow($bucket, 1));   // first accepted
        self::assertFalse($guard->allow($bucket, 1));  // second denied
    }

    public function testAllowUsesLimiterInstanceDirectly(): void
    {
        $limiter = new class {
            private bool $flip = true;

            public function consume(int $tokens)
            {
                $accepted   = $this->flip;
                $this->flip = !$this->flip;

                return new class($accepted) {
                    public function __construct(private bool $accepted)
                    {
                    }

                    public function isAccepted(): bool
                    {
                        return $this->accepted;
                    }
                };
            }
        };

        $guard  = new RateLimiterGuard($limiter);
        $bucket = 'rate:v1:efgh';

        self::assertTrue($guard->allow($bucket, 1));   // true
        self::assertFalse($guard->allow($bucket, 1));  // false after flip
    }

    public function testFailOpenOnLimiterException(): void
    {
        $logger = $this->createMock(GeolocatorLoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $limiter = new class {
            public function consume(int $tokens)
            {
                throw new \RuntimeException('boom');
            }
        };

        $guard  = new RateLimiterGuard($limiter, $logger);
        $bucket = 'rate:v1:ijkl';

        // Should fail-open and return true despite exception
        self::assertTrue($guard->allow($bucket, 1));
    }

    public function testFailOpenWhenNoFactoryAndNoStorageConfigured(): void
    {
        // factory = null and storage = null by default
        $guard  = new RateLimiterGuard();
        $bucket = 'rate:v1:mnop';

        self::assertTrue($guard->allow($bucket, 1));
        self::assertTrue($guard->allow($bucket, 1));
    }
}

<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\Cache;

use Neox\FireGeolocatorBundle\Service\Cache\CacheKeyResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;

final class CacheKeyResolverTest extends TestCase
{
    private function session(string $id, bool $started = true): SessionInterface
    {
        return new class($id, $started) implements SessionInterface {
            private MetadataBag $meta;
            /** @var array<string, SessionBagInterface> */
            private array $bags = [];

            public function __construct(private string $id, private bool $started)
            {
                $this->meta = new MetadataBag();
            }

            public function start(): bool
            {
                $this->started = true;

                return true;
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function setId(string $id): void
            {
                $this->id = $id;
            }

            public function getName(): string
            {
                return 'PHPSESSID';
            }

            public function setName(string $name): void
            {
            }

            public function invalidate(?int $lifetime = null): bool
            {
                return true;
            }

            public function migrate(bool $destroy = false, ?int $lifetime = null): bool
            {
                return true;
            }

            public function save(): void
            {
            }

            public function has(string $name): bool
            {
                return false;
            }

            public function get(string $name, mixed $default = null): mixed
            {
                return null;
            }

            public function set(string $name, mixed $value): void
            {
            }

            public function all(): array
            {
                return [];
            }

            public function replace(array $attributes): void
            {
            }

            public function remove(string $name): mixed
            {
                return null;
            }

            public function clear(): void
            {
            }

            public function isStarted(): bool
            {
                return $this->started;
            }

            public function registerBag(SessionBagInterface $bag): void
            {
                $this->bags[$bag->getName()] = $bag;
            }

            public function getBag(string $name): SessionBagInterface
            {
                return $this->bags[$name];
            }

            public function getMetadataBag(): MetadataBag
            {
                return $this->meta;
            }
        };
    }

    public function testCtxKeyUsesSessionWhenStarted(): void
    {
        $stack = new RequestStack();
        $req   = new Request();
        $req->setSession($this->session('ABC', true));
        $stack->push($req);

        $resolver = new CacheKeyResolver($stack, []);
        $key      = $resolver->ctxKey('ipapi', '1.2.3.4');
        $this->assertStringContainsString('ctx:ipapi:sess:ABC', $key);
    }

    public function testCtxKeyFallsBackToIpWhenNoSession(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request());
        $resolver = new CacheKeyResolver($stack, []);
        $key      = $resolver->ctxKey('ipapi', '1.2.3.4');
        $this->assertStringContainsString('ctx:ipapi:1.2.3.4', $key);
    }
}

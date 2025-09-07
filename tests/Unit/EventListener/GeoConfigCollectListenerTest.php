<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\EventListener;

use Neox\FireGeolocatorBundle\EventListener\GeoConfigCollectListener;
use Neox\FireGeolocatorBundle\Service\Config\GeoAttributeResolver;
use Neox\FireGeolocatorBundle\Service\Log\GeolocatorLoggerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class GeoConfigCollectListenerTest extends TestCase
{
    private function makeEvent(Request $request): RequestEvent
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testWarnsOnceWhenForwardedHeadersPresentAndNoTrustedProxies(): void
    {
        $resolver = new GeoAttributeResolver(null);
        $logger   = $this->createMock(GeolocatorLoggerInterface::class);

        // Expect exactly one warning even if listener is called twice in the same process
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('Forwarded headers present'), $this->arrayHasKey('headers'));

        $listener = new GeoConfigCollectListener($resolver, $logger, ['enabled' => true]);

        // Ensure no trusted proxies configured for this test
        $prevProxies   = Request::getTrustedProxies();
        $prevHeaderSet = Request::getTrustedHeaderSet();
        Request::setTrustedProxies([], $prevHeaderSet);

        try {
            $req1 = Request::create('/path');
            $req1->headers->set('X-Forwarded-For', '1.2.3.4');
            $req1->attributes->set('_controller', 'App\\Controller\\DemoController::index');
            $event1 = $this->makeEvent($req1);
            $listener->onKernelRequest($event1);

            // Second call should not emit a second warning due to once-guard
            $req2 = Request::create('/path2');
            $req2->headers->set('X-Forwarded-For', '5.6.7.8');
            $req2->attributes->set('_controller', 'App\\Controller\\DemoController::index');
            $event2 = $this->makeEvent($req2);
            $listener->onKernelRequest($event2);

            $this->assertTrue(true);
        } finally {
            // restore previous trusted proxy settings
            Request::setTrustedProxies($prevProxies, $prevHeaderSet);
        }
    }
}

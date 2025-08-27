<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\EventListener;

use Neox\FireGeolocatorBundle\EventListener\MaintenanceRequestListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment as TwigEnv;

final class MaintenanceRequestListenerTest extends TestCase
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

    public function testNoopWhenDisabled(): void
    {
        $listener = new MaintenanceRequestListener(['maintenance' => ['enabled' => false]]);
        $request  = Request::create('/any');
        $event    = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        self::assertNull($event->getResponse());
    }

    public function testReturns503WhenEnabledAndAnonymous(): void
    {
        $cfg = [
            'maintenance' => [
                'enabled'         => true,
                'allowed_roles'   => ['ROLE_ADMIN'],
                'paths_whitelist' => [],
                'ips_whitelist'   => [],
                'retry_after'     => 123,
            ],
        ];
        $listener = new MaintenanceRequestListener($cfg);
        $request  = Request::create('/private');
        $event    = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        $resp = $event->getResponse();
        self::assertInstanceOf(Response::class, $resp);
        self::assertSame(503, $resp->getStatusCode());
        self::assertSame('123', $resp->headers->get('Retry-After'));
    }

    public function testPassWhenUserHasAllowedRole(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(true);

        $cfg      = ['maintenance' => ['enabled' => true, 'allowed_roles' => ['ROLE_ADMIN']]];
        $listener = new MaintenanceRequestListener($cfg, $auth);
        $request  = Request::create('/');
        $event    = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        self::assertNull($event->getResponse());
    }

    public function testPassWhenIpWhitelisted(): void
    {
        $cfg      = ['maintenance' => ['enabled' => true, 'ips_whitelist' => ['127.0.0.1']]];
        $listener = new MaintenanceRequestListener($cfg);
        $request  = Request::create('/');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        self::assertNull($event->getResponse());
    }

    public function testPassWhenPathWhitelisted(): void
    {
        $cfg      = ['maintenance' => ['enabled' => true, 'paths_whitelist' => ['/healthz']]];
        $listener = new MaintenanceRequestListener($cfg);
        $request  = Request::create('/healthz/status');
        $event    = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        self::assertNull($event->getResponse());
    }

    public function testTemplateRenderedWhenDefined(): void
    {
        $twig = $this->createMock(TwigEnv::class);
        $twig->method('render')->willReturn('<html>tpl</html>');
        $cfg      = ['maintenance' => ['enabled' => true, 'template' => '@Geolocator/maintenance.html.twig', 'retry_after' => 5]];
        $listener = new MaintenanceRequestListener($cfg, null, null, $twig);
        $request  = Request::create('/');
        $event    = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        $resp = $event->getResponse();
        self::assertInstanceOf(Response::class, $resp);
        self::assertSame(503, $resp->getStatusCode());
        self::assertStringContainsString('tpl', (string) $resp->getContent());
        self::assertSame('5', $resp->headers->get('Retry-After'));
    }

    public function testFallbackMessageWhenNoTemplate(): void
    {
        $cfg      = ['maintenance' => ['enabled' => true, 'template' => null]];
        $listener = new MaintenanceRequestListener($cfg);
        $request  = Request::create('/');
        $event    = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        $resp = $event->getResponse();
        self::assertInstanceOf(Response::class, $resp);
        self::assertStringContainsString('Site en maintenance', (string) $resp->getContent());
    }
}

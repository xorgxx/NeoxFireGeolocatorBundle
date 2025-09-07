<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Tests\Unit\EventListener;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\EventListener\GeolocatorRequestListener;
use Neox\FireGeolocatorBundle\Service\BanManager;
use Neox\FireGeolocatorBundle\Service\Bridge\EventBridgeInterface;
use Neox\FireGeolocatorBundle\Service\ExclusionManager;
use Neox\FireGeolocatorBundle\Service\Filter\FilterChain;
use Neox\FireGeolocatorBundle\Service\GeoContextResolver;
use Neox\FireGeolocatorBundle\Service\Log\GeolocatorLoggerInterface;
use Neox\FireGeolocatorBundle\Service\ResponseFactory;
use Neox\FireGeolocatorBundle\Service\Security\RateLimiterGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class GeolocatorRequestListenerTest extends TestCase
{
    private function makeListener(
        array $config = ['enabled' => true],
        ?GeoContextResolver $resolver = null,
        ?ResponseFactory $responseFactory = null,
        ?BanManager $ban = null,
        ?ExclusionManager $exclusions = null,
        ?GeolocatorLoggerInterface $logger = null,
        ?EventBridgeInterface $bridge = null,
        ?RateLimiterGuard $limiter = null,
        ?FilterChain $filters = null
    ): GeolocatorRequestListener {
        $resolver        ??= $this->createMock(GeoContextResolver::class);
        $responseFactory ??= $this->createMock(ResponseFactory::class);
        $ban             ??= $this->createMock(BanManager::class);
        $exclusions      ??= $this->createMock(ExclusionManager::class);
        $logger          ??= $this->createMock(GeolocatorLoggerInterface::class);
        $bridge          ??= $this->createMock(EventBridgeInterface::class);
        $limiter         ??= new RateLimiterGuard(null);
        $filters         ??= $this->createMock(FilterChain::class);

        // reasonable defaults for mocks
        $exclusions->method('isExcluded')->willReturn(false);
        $ban->method('isBanned')->willReturn(false);
        $filters->method('decide')->willReturn(null);

        return new GeolocatorRequestListener(
            $config,
            $resolver,
            $responseFactory,
            $ban,
            $exclusions,
            $logger,
            $bridge,
            $limiter,
            $filters
        );
    }

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

    private function makeCfg(array $over = []): ResolvedGeoApiConfigDTO
    {
        $cfg = new ResolvedGeoApiConfigDTO();
        foreach ($over as $k => $v) {
            $cfg->$k = $v;
        }

        return $cfg;
    }

    public function testSimulateOverrideFromQueryParamTrue(): void
    {
        $listener = $this->makeListener();

        $request = Request::create('/demo?geo_simulate=1');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'app_demo');
        $cfg = $this->makeCfg(['simulate' => false, 'enabled' => true, 'trusted' => ['routes' => []]]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue($request->attributes->get('geolocator_simulate'));
    }

    public function testRouteExemptBypassesProcessing(): void
    {
        $resolver = $this->createMock(GeoContextResolver::class);
        $resolver->expects($this->never())->method('resolve');

        $listener = $this->makeListener([], $resolver);

        $request = Request::create('/_profiler');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'debug_profiler');
        $cfg = $this->makeCfg(['enabled' => true, 'trusted' => ['routes' => ['debug_*']]]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        // No exception and resolver not called is sufficient to prove bypass
        $this->assertTrue(true);
    }

    public function testRouteExemptExactMatch(): void
    {
        $resolver = $this->createMock(GeoContextResolver::class);
        $resolver->expects($this->never())->method('resolve');
        $listener = $this->makeListener([], $resolver);

        $request = Request::create('/home');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'app_home');
        $cfg = $this->makeCfg(['enabled' => true, 'trusted' => ['routes' => ['app_home']]]);
        $request->attributes->set('geolocator_config', $cfg);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        $this->assertTrue(true);
    }

    public function testRouteExemptPrefixWildcard(): void
    {
        $resolver = $this->createMock(GeoContextResolver::class);
        $resolver->expects($this->never())->method('resolve');
        $listener = $this->makeListener([], $resolver);

        $request = Request::create('/admin');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'admin_dashboard');
        $cfg = $this->makeCfg(['enabled' => true, 'trusted' => ['routes' => ['admin_*']]]);
        $request->attributes->set('geolocator_config', $cfg);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        $this->assertTrue(true);
    }

    public function testRouteExemptSuffixWildcard(): void
    {
        $resolver = $this->createMock(GeoContextResolver::class);
        $resolver->expects($this->never())->method('resolve');
        $listener = $this->makeListener([], $resolver);

        $request = Request::create('/article/preview');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'post__preview');
        $cfg = $this->makeCfg(['enabled' => true, 'trusted' => ['routes' => ['*__preview']]]);
        $request->attributes->set('geolocator_config', $cfg);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        $this->assertTrue(true);
    }

    public function testRouteExemptQuestionMarkSingleChar(): void
    {
        $resolver = $this->createMock(GeoContextResolver::class);
        $resolver->expects($this->never())->method('resolve');
        $listener = $this->makeListener([], $resolver);

        $request = Request::create('/demo');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'app_demo');
        $cfg = $this->makeCfg(['enabled' => true, 'trusted' => ['routes' => ['app_dem?']]]);
        $request->attributes->set('geolocator_config', $cfg);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        $this->assertTrue(true);
    }

    public function testRouteNotExemptWhenNoMatch(): void
    {
        $resolver = $this->createMock(GeoContextResolver::class);
        $resolver->expects($this->once())->method('resolve')->willReturn(null);
        $listener = $this->makeListener([], $resolver);

        $request = Request::create('/user');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'user_dashboard');
        $cfg = $this->makeCfg(['enabled' => true, 'trusted' => ['routes' => ['admin_*']]]);
        $request->attributes->set('geolocator_config', $cfg);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        $this->assertTrue(true);
    }

    public function testRouteExemptCatchAllStar(): void
    {
        $resolver = $this->createMock(GeoContextResolver::class);
        $resolver->expects($this->never())->method('resolve');
        $listener = $this->makeListener([], $resolver);

        $request = Request::create('/anything');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'any_route_name');
        $cfg = $this->makeCfg(['enabled' => true, 'trusted' => ['routes' => ['*']]]);
        $request->attributes->set('geolocator_config', $cfg);
        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);
        $this->assertTrue(true);
    }

    public function testRateLimitDeniesWhenNotSimulated(): void
    {
        // Fake limiter that always rejects tokens
        $fakeLimiter = new class {
            public function consume(int $tokens)
            {
                return new class {
                    public function isAccepted(): bool
                    {
                        return false;
                    }
                };
            }
        };
        $limiterGuard = new RateLimiterGuard($fakeLimiter);

        $responseFactory = $this->createMock(ResponseFactory::class);
        $responseFactory->expects($this->once())->method('denied')->willReturn(new Response('', 429));

        $listener = $this->makeListener([], null, $responseFactory, null, null, null, null, $limiterGuard);

        $request = Request::create('/path');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'app_demo');
        $cfg = $this->makeCfg(['simulate' => false, 'enabled' => true, 'trusted' => ['routes' => []]]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $this->assertTrue(true); // assertion handled by mock expectations
    }

    public function testBannedBlocksWhenNotSimulated(): void
    {
        $ban = $this->createMock(BanManager::class);
        $ban->method('isBanned')->willReturn(true);

        $responseFactory = $this->createMock(ResponseFactory::class);
        $responseFactory->expects($this->once())->method('banned')->willReturn(new Response('', 403));

        $listener = $this->makeListener([], null, $responseFactory, $ban);

        $request = Request::create('/path');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'app_demo');
        $cfg = $this->makeCfg(['simulate' => false, 'enabled' => true, 'trusted' => ['routes' => []]]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $this->assertTrue(true);
    }

    public function testProviderErrorDeniedWhenBlockOnErrorTrue(): void
    {
        $resolver = $this->createMock(GeoContextResolver::class);
        $resolver->method('resolve')->willReturn(null);

        $responseFactory = $this->createMock(ResponseFactory::class);
        $responseFactory->expects($this->once())->method('denied')->willReturn(new Response('', 503));

        $listener = $this->makeListener([], $resolver, $responseFactory);

        $request = Request::create('/path');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'app_demo');
        $cfg = $this->makeCfg(['simulate' => false, 'enabled' => true, 'blockOnError' => true, 'trusted' => ['routes' => []]]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $this->assertTrue(true);
    }

    public function testFiltersDenyIncrementsAndDenies(): void
    {
        $filters = $this->createMock(FilterChain::class);
        $filters->method('decide')->willReturn(new AuthorizationDTO(false, 'reason', 'category'));

        $ban = $this->createMock(BanManager::class);
        $ban->expects($this->once())->method('increment');

        $responseFactory = $this->createMock(ResponseFactory::class);
        $responseFactory->expects($this->once())->method('denied')->willReturn(new Response('', 403));

        $listener = $this->makeListener([], null, $responseFactory, $ban, null, null, null, null, $filters);

        $request = Request::create('/path');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'app_demo');
        $cfg = $this->makeCfg(['simulate' => false, 'enabled' => true, 'blockOnError' => false, 'trusted' => ['routes' => []]]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $this->assertTrue(true);
    }

    public function testSimulateOverrideFromQueryParamFalse(): void
    {
        $listener = $this->makeListener();

        $request = Request::create('/demo?geo_simulate=0');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'app_demo');
        // Config globale à true, requête doit forcer false
        $cfg = $this->makeCfg(['simulate' => true, 'enabled' => true, 'trusted' => ['routes' => []]]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertFalse($request->attributes->get('geolocator_simulate'));
    }

    public function testRateLimitDoesNotDenyWhenSimulated(): void
    {
        // Limiteur qui refuse toujours
        $fakeLimiter = new class {
            public function consume(int $tokens)
            {
                return new class {
                    public function isAccepted(): bool
                    {
                        return false;
                    }
                };
            }
        };
        $limiterGuard = new RateLimiterGuard($fakeLimiter);

        $responseFactory = $this->createMock(ResponseFactory::class);
        // En mode simulate, denied() ne doit PAS être appelé
        $responseFactory->expects($this->never())->method('denied');

        $listener = $this->makeListener([], null, $responseFactory, null, null, null, null, $limiterGuard);

        $request = Request::create('/path?geo_simulate=1');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'app_demo');
        $cfg = $this->makeCfg(['simulate' => false, 'enabled' => true, 'trusted' => ['routes' => []]]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'No response should be set in simulate mode on rate-limit');
    }

    public function testBannedDoesNotBlockWhenSimulated(): void
    {
        $ban = $this->createMock(BanManager::class);
        $ban->method('isBanned')->willReturn(true);

        $responseFactory = $this->createMock(ResponseFactory::class);
        $responseFactory->expects($this->never())->method('banned');

        $listener = $this->makeListener([], null, $responseFactory, $ban);

        $request = Request::create('/path?geo_simulate=1');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'app_demo');
        $cfg = $this->makeCfg(['simulate' => false, 'enabled' => true, 'trusted' => ['routes' => []]]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'No response should be set in simulate mode when banned');
    }

    public function testProviderErrorIgnoredWhenSimulated(): void
    {
        $resolver = $this->createMock(GeoContextResolver::class);
        // provider renvoie null
        $resolver->method('resolve')->willReturn(null);

        $responseFactory = $this->createMock(ResponseFactory::class);
        $responseFactory->expects($this->never())->method('denied');

        $listener = $this->makeListener([], $resolver, $responseFactory);

        $request = Request::create('/path?geo_simulate=1');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'app_demo');
        $cfg = $this->makeCfg([
            'simulate'     => false, // surchargé par la query
            'enabled'      => true,
            'blockOnError' => true,
            'trusted'      => ['routes' => []],
        ]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'No response should be set in simulate mode when provider fails');
    }

    public function testFiltersAllowSetsAuthAttribute(): void
    {
        $filters = $this->createMock(FilterChain::class);
        $filters->method('decide')->willReturn(new AuthorizationDTO(true, null, null));

        $listener = $this->makeListener([], null, null, null, null, null, null, null, $filters);

        $request = Request::create('/path');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'app_demo');
        $cfg = $this->makeCfg(['simulate' => false, 'enabled' => true, 'trusted' => ['routes' => []]]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        $auth = $request->attributes->get('geolocator_auth');
        self::assertInstanceOf(AuthorizationDTO::class, $auth);
        self::assertTrue($auth->allowed);
        self::assertNull($event->getResponse(), 'No response should be set when allowed');
    }

    public function testRoutePatternsIgnoreNonStringEntries(): void
    {
        $resolver = $this->createMock(GeoContextResolver::class);
        // Pas exempt -> resolve doit être appelé
        $resolver->expects($this->once())->method('resolve')->willReturn(null);
        $listener = $this->makeListener([], $resolver);

        $request = Request::create('/user');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        $request->attributes->set('_route', 'user_dashboard');
        // Patterns mixtes: valeurs invalides ignorées
        $cfg = $this->makeCfg(['enabled' => true, 'trusted' => ['routes' => ['', null, [], 123, 'admin_*']]]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue(true);
    }

    public function testRouteWildcardMatchIsCaseInsensitive(): void
    {
        $resolver = $this->createMock(GeoContextResolver::class);
        $resolver->expects($this->never())->method('resolve');
        $listener = $this->makeListener([], $resolver);

        $request = Request::create('/admin');
        $request->attributes->set('_controller', 'App\\Controller\\DemoController::index');
        // Nom de route en casse différente
        $request->attributes->set('_route', 'Admin_Dashboard');
        $cfg = $this->makeCfg(['enabled' => true, 'trusted' => ['routes' => ['admin_*']]]);
        $request->attributes->set('geolocator_config', $cfg);

        $event = $this->makeEvent($request);
        $listener->onKernelRequest($event);

        self::assertTrue(true);
    }
}

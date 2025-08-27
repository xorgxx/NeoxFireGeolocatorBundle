<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final class TrustedProxyRequestListener
{
    public function __construct(private array $geolocatorConfig)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $proxies = (array) ($this->geolocatorConfig['trusted']['proxies'] ?? []);
        $headers = (array) ($this->geolocatorConfig['trusted']['headers'] ?? []);

        // Normalisation simple IP/CIDR
        $proxies = array_values(array_filter(array_map('trim', $proxies), static function (string $p): bool {
            if ($p === '') {
                return false;
            }
            if (str_contains($p, '/')) {
                [$ip, $mask] = explode('/', $p, 2);

                return filter_var($ip, FILTER_VALIDATE_IP) && is_numeric($mask);
            }

            return (bool) filter_var($p, FILTER_VALIDATE_IP);
        }));

        // Construire le masque d’en-têtes “de confiance”
        $map = [
            'x-forwarded-for'    => Request::HEADER_X_FORWARDED_FOR,
            'x-forwarded-host'   => Request::HEADER_X_FORWARDED_HOST,
            'x-forwarded-proto'  => Request::HEADER_X_FORWARDED_PROTO,
            'x-forwarded-port'   => Request::HEADER_X_FORWARDED_PORT,
            'x-forwarded-prefix' => Request::HEADER_X_FORWARDED_PREFIX,
        ];
        $mask = 0;
        foreach ($headers as $h) {
            $k = strtolower((string) $h);
            if (isset($map[$k])) {
                $mask |= $map[$k];
            }
        }
        if ($mask === 0) {
            // fallback raisonnable
            $mask = Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PORT;
        }
        // Clamp to allowed bitmask range (limit to known flags)
        $all = Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PREFIX;
        $mask = $mask & $all;

        if ($proxies) {
            Request::setTrustedProxies($proxies, $mask);
        }
    }
}

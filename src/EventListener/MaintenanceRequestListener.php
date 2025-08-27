<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\EventListener;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment as TwigEnv;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 255)]
final class MaintenanceRequestListener
{
    private const CACHE_KEY = 'neox_fire_geolocator_maintenance_flag';

    private CacheItemPoolInterface $cache;
    private array $config                        = [];
    private ?AuthorizationCheckerInterface $auth = null;
    private ?TwigEnv $twig                       = null;

    public function __construct(
        array $config = [],
        ?AuthorizationCheckerInterface $auth = null,
        ?CacheItemPoolInterface $cache = null,
        ?TwigEnv $twig = null,
    ) {
        $this->config = $config;
        $this->auth   = $auth;
        $this->twig   = $twig;
        // Use a default memory cover if none is provided (avoid errors in testing)
        $this->cache = $cache ?? new ArrayAdapter();
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // 1) Lire l’état dynamique depuis le cache (prioritaire)
        $enabled    = false;
        $untilIso   = null;
        $sinceIso   = null;
        $comment    = null;
        $ttlVal     = null;
        $retryAfter = (int) ($this->config['maintenance']['retry_after'] ?? 600);

        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            $payload = $item->get();
            if (is_array($payload)) {
                $enabled  = (bool) ($payload['enabled'] ?? false);
                $untilIso = $payload['until']   ?? null;
                $sinceIso = $payload['since']   ?? null;
                $comment  = $payload['comment'] ?? null;
                $ttlVal   = $payload['ttl']     ?? null;
            }
        } else {
            // 2) Retomber sur la config si pas d’item en cache
            $enabled = (bool) ($this->config['maintenance']['enabled'] ?? false);
        }

        if (!$enabled) {
            return;
        }

        // 3) Whitelists
        if ($this->isPathWhitelisted($request)) {
            return;
        }
        if ($this->isIpWhitelisted($request)) {
            return;
        }
        if ($this->isRoleAllowed()) {
            return;
        }

        // 4) Réponse 503 Maintenance
        $response = new Response();
        $response->setStatusCode(503, 'Service Unavailable');

        // Retry-After: préférer until (date HTTP), sinon ttl (secondes), sinon config
        if ($untilIso && ($ts = strtotime($untilIso)) !== false) {
            $response->headers->set('Retry-After', gmdate('D, d M Y H:i:s', $ts) . ' GMT');
        } elseif (is_numeric($ttlVal) && (int) $ttlVal > 0) {
            $response->headers->set('Retry-After', (string) max(1, (int) $ttlVal));
        } else {
            $response->headers->set('Retry-After', (string) max(1, $retryAfter));
        }

        // Exposer des en-têtes informatifs (facultatif, utile pour supervision)
        if ($sinceIso) {
            $response->headers->set('X-Maintenance-Since', $sinceIso);
        }
        if ($untilIso) {
            $response->headers->set('X-Maintenance-Until', $untilIso);
        }
        if (is_numeric($ttlVal)) {
            $response->headers->set('X-Maintenance-TTL', (string) max(0, (int) $ttlVal));
        }
        if (is_string($comment) && $comment !== '') {
            // Attention à la longueur; tronquez si besoin
            $response->headers->set('X-Maintenance-Comment', mb_strimwidth($comment, 0, 200, '…'));
        }

        $message = $this->config['maintenance']['message'] ?? 'Site en maintenance. Veuillez réessayer plus tard.';

        // Try to render Twig template if configured or available, else fallback to plain message
        $html     = null;
        $template = $this->config['maintenance']['template'] ?? '@NeoxFireGeolocator/maintenance.html.twig';
        $context  = [
            'message'     => $message,
            'retry_after' => $retryAfter ?? null,
            'comment'     => $comment,
            'since'       => $sinceIso,
            'until'       => $untilIso,
            'ttl'         => $ttlVal,
        ];
        // pass potential release/until info if present in cache/config
        if ($untilIso) {
            try {
                $context['release_at'] = new \DateTimeImmutable($untilIso);
            } catch (\Exception) {
                // ignore parse error
            }
        }

        if ($this->twig && is_string($template) && $template !== '') {
            try {
                $html = $this->twig->render($template, $context);
            } catch (\Throwable $e) {
                $html = null; // fallback to message
            }
        }

        if ($html !== null) {
            $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
            $response->setContent($html);
        } else {
            // Fallback texte enrichi avec le commentaire et les horodatages si disponibles
            $lines = [$message];
            if (is_string($comment) && $comment !== '') {
                $lines[] = 'Commentaire: ' . $comment;
            }
            if ($sinceIso) {
                $lines[] = 'Depuis: ' . $sinceIso;
            }
            if ($untilIso) {
                $lines[] = 'Jusqu\'à: ' . $untilIso;
            }
            if (is_numeric($ttlVal)) {
                $lines[] = 'TTL restant (approx): ' . max(0, (int) $ttlVal) . 's';
            }
            $response->setContent(implode("\n", $lines));
        }

        $event->setResponse($response);
    }

    private function isPathWhitelisted(Request $request): bool
    {
        $whitelist = (array) ($this->config['maintenance']['paths_whitelist'] ?? []);
        if (!$whitelist) {
            return false;
        }
        $path = $request->getPathInfo();
        foreach ($whitelist as $prefix) {
            if ($prefix !== '' && str_starts_with($path, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isIpWhitelisted(Request $request): bool
    {
        $ips = (array) ($this->config['maintenance']['ips_whitelist'] ?? []);
        if (!$ips) {
            return false;
        }
        $clientIp = $request->getClientIp();
        if (!$clientIp) {
            return false;
        }

        // match simple exact; une extension CIDR peut être ajoutée si nécessaire
        return in_array($clientIp, $ips, true);
    }

    private function isRoleAllowed(): bool
    {
        $roles = (array) ($this->config['maintenance']['allowed_roles'] ?? []);
        if (!$roles || $this->auth === null) {
            return false;
        }
        foreach ($roles as $role) {
            if (is_string($role) && $role !== '' && $this->auth->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}

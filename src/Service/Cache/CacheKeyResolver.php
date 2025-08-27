<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Cache;

use Symfony\Component\HttpFoundation\RequestStack;

final class CacheKeyResolver
{
    public function __construct(
        private RequestStack $requestStack,
        private array $config = [] // e.g. ['strategy' => 'ip'|'session']
    ) {
    }

    public function ctxKey(string $provider, string $ip): string
    {
        // Strategy selection: explicit config > session available > fallback to IP
        $strategy = strtolower((string) ($this->config['strategy'] ?? ''));
        if ($strategy === 'session') {
            $sid = $this->getSessionId();
            if ($sid !== null) {
                return CacheKey::ctxSession($provider, $sid);
            }
        } elseif ($strategy === 'ip') {
            return CacheKey::ctx($provider, $ip);
        }

        // auto: use session if started else ip
        $sid = $this->getSessionId();
        if ($sid !== null) {
            return CacheKey::ctxSession($provider, $sid);
        }

        return CacheKey::ctx($provider, $ip);
    }
    // ... existing code ...

    private function getSessionId(): ?string
    {
        $req = $this->requestStack->getCurrentRequest();
        if (!$req) {
            return null;
        }

        // Important: ne pas appeler getSession() si la Request n'a pas de session
        if (!$req->hasSession()) {
            return null;
        }

        $session = $req->getSession();
        if (!$session->isStarted()) {
            // Évitez de démarrer la session de force ici: laissez le contrôleur/middleware gérer ça
            return null;
        }

        return $session->getId() ?: null;
    }
}

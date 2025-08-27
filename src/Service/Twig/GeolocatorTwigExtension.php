<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Twig;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Cache\CacheKey;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment as TwigEnv;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class GeolocatorTwigExtension extends AbstractExtension
{
    public function __construct(private TwigEnv $twig, private RequestStack $rs, private CacheItemPoolInterface $cache)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('geolocator_profile_bar', [$this, 'renderProfileBar'], ['is_safe' => ['html']]),
            new TwigFunction('country_flag', [$this, 'countryFlag']),
        ];
    }

    public function renderProfileBar(): string
    {
        $req = $this->rs->getCurrentRequest();
        if (!$req) {
            return '';
        }

        /** @var GeoApiContextDTO|null $ctx */
        $ctx = null;
        /** @var AuthorizationDTO|null $auth */
        $auth = null;

        /** @var ResolvedGeoApiConfigDTO|null $cfg */
        $cfg      = $req->attributes->get('geolocator_config');
        $provider = 'findip';
        if ($cfg instanceof ResolvedGeoApiConfigDTO) {
            $provider = $cfg->getSelectedProviderAlias($cfg->getProviders()['list'] ?? []) ?? $provider;
        }

        $key = null;
        if ($cfg instanceof ResolvedGeoApiConfigDTO && $cfg->getCacheKeyStrategy() === 'session') {
            $sid = $this->resolveSessionId($req);
            if ($sid) {
                $key = $this->normalize(CacheKey::ctxSession($provider, $sid));
            }
        }
        if (!$key) {
            $ipGuess = $req->getClientIp() ?? '';
            $key     = $this->normalize(CacheKey::ctx($provider, $ipGuess));
        }

        // Prefer cache for both context and authorization
        try {
            $item = $this->cache->getItem($key);
            if ($item->isHit()) {
                $val = $item->get();
                if ($val instanceof GeoApiContextDTO) {
                    $ctx = $val;
                } elseif (is_array($val)) {
                    if (isset($val['ctx']) && $val['ctx'] instanceof GeoApiContextDTO) {
                        $ctx = $val['ctx'];
                    } elseif (isset($val['ip'])) {
                        // Backward compatibility: hydrate from legacy array (do not write back to preserve metadata like _exp/auth)
                        $ctx = new GeoApiContextDTO(
                            ip: (string) ($val['ip'] ?? ''),
                            country: $val['country']         ?? null,
                            countryCode: $val['countryCode'] ?? null,
                            region: $val['region']           ?? null,
                            city: $val['city']               ?? null,
                            lat: isset($val['lat']) ? (float) $val['lat'] : null,
                            lon: isset($val['lon']) ? (float) $val['lon'] : null,
                            isp: $val['isp']         ?? null,
                            asn: $val['asn']         ?? null,
                            proxy: $val['proxy']     ?? null,
                            hosting: $val['hosting'] ?? null,
                            raw: is_array($val['raw'] ?? null) ? $val['raw'] : $val
                        );
                    }
                    if (isset($val['auth']) && $val['auth'] instanceof AuthorizationDTO) {
                        $auth = $val['auth'];
                    }
                }
            }
        } catch (\Throwable) {
            // ignore cache errors
        }

        // Fallback to request attributes only if cache missed
        if (!$ctx) {
            $ctx = $req->attributes->get('geolocator_context');
        }
        if (!$auth) {
            $auth = $req->attributes->get('geolocator_auth');
        }

        return $this->twig->render('@Geolocator/partials/profile_bar.html.twig', [
            'ctx'      => $ctx,
            'auth'     => $auth,
            'simulate' => (bool) ($req->attributes->get('geolocator_simulate') ?? false),
        ]);
    }

    private function resolveSessionId(Request $req): ?string
    {
        try {
            if (!$req->hasSession()) {
                return null;
            }
            $session = $req->getSession();
            if (method_exists($session, 'isStarted') && $session->isStarted()) {
                $id = $session->getId();

                return is_string($id) && $id !== '' ? $id : null;
            }
            $rawName = method_exists($session, 'getName') ? $session->getName() : (function_exists('session_name') ? session_name() : null);
            $name    = is_string($rawName) && $rawName !== '' ? $rawName : 'PHPSESSID';
            $cookie  = $req->cookies->get($name);

            return is_string($cookie) && $cookie !== '' ? $cookie : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalize(string $key): string
    {
        // PSR-6 allowed characters: A-Z a-z 0-9 _ .
        return preg_replace('/[^A-Za-z0-9_.]/', '_', $key);
    }

    public function countryFlag(?string $code): string
    {
        if (!$code) {
            return '';
        }
        $code = strtoupper($code);
        $out  = '';
        for ($i = 0; $i < strlen($code); ++$i) {
            $out .= mb_chr(0x1F1E6 - ord('A') + ord($code[$i]), 'UTF-8');
        }

        return $out;
    }
}

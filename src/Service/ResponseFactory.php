<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\Service\Cache\StorageInterface;
use Neox\FireGeolocatorBundle\Service\Privacy\AnonymizationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment as TwigEnv;

class ResponseFactory
{
    public function __construct(
        private TwigEnv $twig,
        private ResponseFormatNegotiator $negotiator,
        private RequestStack $rs,
        private ?StorageInterface $storage = null,
        private array $config = [],
        private ?RouterInterface $router = null,
        private ?TranslatorInterface $translator = null,
        private ?AnonymizationService $privacy = null,
    ) {
    }

    public function denied(?string $redirect, AuthorizationDTO $auth, ?GeoApiContextDTO $ctx): Response
    {
        $req = $this->rs->getCurrentRequest();
        if ($redirect) {
            $paramsRaw = $req?->attributes->get('_route_params', []) ?? [];
            $params    = is_array($paramsRaw) ? $paramsRaw : [];
            $url       = $this->resolveRedirect($redirect, $params);

            return new RedirectResponse($url, 302);
        }
        if ($req) {
            // Problem+JSON takes precedence when explicitly requested
            if ($this->negotiator->wantsProblemJson($req)) {
                $payload = [
                    'type'           => 'about:blank',
                    'title'          => $this->t('problem.denied.title', 'Access denied'),
                    'status'         => 403,
                    'detail'         => $auth->reason,
                    'instance'       => $req->getPathInfo(),
                    'blockingFilter' => $auth->blockingFilter,
                    'context'        => $ctx ? ['ip_hash' => ($this->privacy?->anonymizeIp($ctx->ip ?? '') ?? null), 'country' => $ctx->country, 'countryCode' => $ctx->countryCode] : null,
                ];
                $resp = new JsonResponse($payload, 403, ['Vary' => 'Accept, X-Requested-With', 'Content-Type' => 'application/problem+json']);

                return $this->annotateSimulate($resp);
            }
            if ($this->negotiator->wantsJson($req)) {
                $resp = new JsonResponse([
                    'allowed' => false,
                    'reason'  => $auth->reason,
                    'context' => $ctx ? ['ip_hash' => ($this->privacy?->anonymizeIp($ctx->ip ?? '') ?? null), 'country' => $ctx->country, 'countryCode' => $ctx->countryCode] : null,
                ], 403, ['Vary' => 'Accept, X-Requested-With']);

                return $this->annotateSimulate($resp);
            }
        }

        // Compute attempts and remaining attempts for display
        $ip  = $ctx?->ip ?: ($req?->getClientIp() ?: null);
        $sid = null;
        try {
            if ($req && method_exists($req, 'hasSession') && $req->hasSession()) {
                $session = $req->getSession();
                if (method_exists($session, 'isStarted') && $session->isStarted()) {
                    $sid = $session->getId() ?: null;
                } else {
                    $rawName = method_exists($session, 'getName') ? $session->getName() : (function_exists('session_name') ? session_name() : null);
                    $name    = is_string($rawName) && $rawName !== '' ? $rawName : 'PHPSESSID';
                    $cookie  = $req->cookies->get($name);
                    $sid     = is_string($cookie) && $cookie !== '' ? $cookie : null;
                }
            }
        } catch (\Throwable) {
            $sid = null;
        }
        $bucket       = ($ip && $this->privacy) ? $this->privacy->buildBanKey($ip, $sid) : null;
        $attempts     = 0;
        $attemptsLeft = null;
        $attemptsTtl  = null;
        try {
            if ($bucket && $this->storage) {
                $attempts    = $this->storage->getAttempts($bucket);
                $attemptsTtl = $this->storage->getAttemptsTtl($bucket);
            }
        } catch (\Throwable) {
            // ignore storage failures
        }
        $maxAttempts  = (int) ($this->config['bans']['max_attempts'] ?? 10);
        $attemptsLeft = max(0, $maxAttempts - (int) $attempts);

        $ipHash = $ip ? ($this->privacy?->anonymizeIp($ip) ?? null) : null;
        try {
            $html = $this->twig->render('@NeoxFireGeolocator/deny.html.twig', [
                'auth'          => $auth,
                'ctx'           => $ctx,
                'attempts'      => $attempts,
                'attempts_left' => $attemptsLeft,
                'attempts_ttl'  => $attemptsTtl,
                'ip_hash'       => $ipHash,
            ]);
        } catch (\Twig\Error\LoaderError) {
            $html = '<h1>Access denied</h1>';
        }

        return $this->annotateSimulate(new Response($html, 403));
    }

    public function banned(?string $redirect, ?GeoApiContextDTO $ctx): Response
    {
        $req = $this->rs->getCurrentRequest();
        if ($redirect) {
            $paramsRaw = $req?->attributes->get('_route_params', []) ?? [];
            $params    = is_array($paramsRaw) ? $paramsRaw : [];
            $url       = $this->resolveRedirect($redirect, $params);

            return new RedirectResponse($url, 302);
        }

        $ip  = $ctx?->ip ?: ($req?->getClientIp() ?: null);
        $sid = null;
        try {
            if ($req && method_exists($req, 'hasSession') && $req->hasSession()) {
                $session = $req->getSession();
                if (method_exists($session, 'isStarted') && $session->isStarted()) {
                    $sid = $session->getId() ?: null;
                } else {
                    $rawName = method_exists($session, 'getName') ? $session->getName() : (function_exists('session_name') ? session_name() : null);
                    $name    = is_string($rawName) && $rawName !== '' ? $rawName : 'PHPSESSID';
                    $cookie  = $req->cookies->get($name);
                    $sid     = is_string($cookie) && $cookie !== '' ? $cookie : null;
                }
            }
        } catch (\Throwable) {
            $sid = null;
        }
        $bucket = ($ip && $this->privacy) ? $this->privacy->buildBanKey($ip, $sid) : null;

        $attempts    = 0;
        $attemptsTtl = null;
        $banTtl      = null;
        $ban         = null;
        $retryAt     = null;
        try {
            if ($bucket && $this->storage) {
                $attempts    = $this->storage->getAttempts($bucket);
                $attemptsTtl = $this->storage->getAttemptsTtl($bucket);
                if ($this->storage->isBanned($bucket)) {
                    $ban    = $this->storage->getBanInfo($bucket);
                    $banTtl = $this->storage->getBanTtl($bucket);
                    if (is_array($ban) && !empty($ban['banned_until'])) {
                        $retryAt = $ban['banned_until'];
                    } elseif (is_int($banTtl)) {
                        // compute retryAt from now + banTtl
                        $retryAt = gmdate('c', time() + $banTtl);
                    }
                }
            }
        } catch (\Throwable) {
            // ignore storage failures in user-facing page
        }

        if ($req && $this->negotiator->wantsProblemJson($req)) {
            $payload = [
                'type'     => 'about:blank',
                'title'    => $this->t('problem.banned.title', 'Too Many Requests'),
                'status'   => 429,
                'detail'   => $this->t('problem.banned.detail', 'You have been temporarily blocked due to too many attempts.'),
                'instance' => $req->getPathInfo(),
                'retry_at' => $retryAt,
                'context'  => $ctx ? ['ip_hash' => ($this->privacy?->anonymizeIp($ctx->ip ?? '') ?? null), 'country' => $ctx->country, 'countryCode' => $ctx->countryCode] : null,
            ];
            $resp = new JsonResponse($payload, 429, ['Vary' => 'Accept, X-Requested-With', 'Content-Type' => 'application/problem+json']);

            return $this->annotateSimulate($resp);
        }

        $ipHash = $ip ? ($this->privacy?->anonymizeIp($ip) ?? null) : null;
        try {
            $html = $this->twig->render('@NeoxFireGeolocator/banned.html.twig', [
                'ctx'          => $ctx,
                'attempts'     => $attempts,
                'attempts_ttl' => $attemptsTtl,
                'ban'          => $ban,
                'ban_ttl'      => $banTtl,
                'ip_hash'      => $ipHash,
                'retry_at'     => $retryAt,
            ]);
        } catch (\Twig\Error\LoaderError) {
            $html = '<h1>banned</h1>';
        }

        return $this->annotateSimulate(new Response($html, 429));
    }

    private function resolveRedirect(string $redirect, array $routeParams = []): string
    {
        // Treat as route name if not an absolute/relative URL and router is available
        if ($this->router && !str_starts_with($redirect, '/') && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $redirect)) {
            try {
                return $this->router->generate($redirect, $routeParams);
            } catch (\Throwable) {
                // fall through to raw redirect string
            }
        }

        return $redirect;
    }

    private function annotateSimulate(Response $response): Response
    {
        try {
            $simulate = (bool) ($this->rs->getCurrentRequest()?->attributes->get('geolocator_simulate') ?? false);
            $response->headers->set('X-Geolocator-Simulate', $simulate ? '1' : '0');
        } catch (\Throwable) {
            // ignore
        }

        return $response;
    }

    private function t(string $id, string $fallback, array $parameters = [], string $domain = 'geolocator'): string
    {
        try {
            if ($this->translator) {
                $translated = $this->translator->trans($id, $parameters, $domain);
                if ($translated !== $id) {
                    return $translated;
                }
            }
        } catch (\Throwable) {
            // ignore translation failures and return fallback
        }

        return $fallback;
    }
}

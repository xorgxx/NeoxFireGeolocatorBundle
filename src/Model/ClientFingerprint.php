<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Model;

use Symfony\Component\HttpFoundation\RequestStack;

class ClientFingerprint
{
    public function __construct(private RequestStack $rs, private ?string $salt = null)
    {
    }

    public function fingerprint(): string
    {
        $r = $this->rs->getCurrentRequest();
        if (!$r) {
            return 'cli:unknown';
        }
        $parts = [
            $r->getClientIp(),
            $r->headers->get('User-Agent', ''),
            $r->headers->get('Accept-Language', ''),
            $this->salt ?? '',
        ];

        return 'cli:' . substr(hash('sha256', implode('|', $parts)), 0, 16);
    }
}

<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service;

use Symfony\Component\HttpFoundation\Request;

class ResponseFormatNegotiator
{
    public function wantsJson(Request $request): bool
    {
        if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }
        $accept = $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json') || str_contains($accept, 'application/problem+json');
    }

    public function wantsProblemJson(Request $request): bool
    {
        $accept = $request->headers->get('Accept', '');

        return str_contains($accept, 'application/problem+json');
    }
}

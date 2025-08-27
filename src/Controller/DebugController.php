<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;

class DebugController
{
    public function health(): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'ts' => time()]);
    }
}

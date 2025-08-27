<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Bridge;

interface EventBridgeInterface
{
    public function notify(string $event, array $payload = []): void;
}

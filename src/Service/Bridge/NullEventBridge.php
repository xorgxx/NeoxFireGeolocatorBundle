<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Bridge;

class NullEventBridge implements EventBridgeInterface
{
    public function notify(string $event, array $payload = []): void
    { /* noop */
    }
}

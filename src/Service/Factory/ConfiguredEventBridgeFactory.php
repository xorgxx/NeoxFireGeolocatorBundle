<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Factory;

use Neox\FireGeolocatorBundle\Service\Bridge\EventBridgeInterface;
use Neox\FireGeolocatorBundle\Service\Bridge\NullEventBridge;
use Psr\Container\ContainerInterface;

class ConfiguredEventBridgeFactory
{
    public function __construct(private ContainerInterface $container, private array $config)
    {
    }

    public function create(): EventBridgeInterface
    {
        $id = $this->config['event_bridge_service'] ?? null;
        if ($id && $this->container->has($id)) {
            $svc = $this->container->get($id);
            if ($svc instanceof EventBridgeInterface) {
                return $svc;
            }
        }

        return new NullEventBridge();
    }
}

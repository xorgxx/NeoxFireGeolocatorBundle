<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Log;

interface GeolocatorLoggerInterface
{
    public function debug(string $message, array $context = []): void;

    public function info(string $message, array $context = []): void;

    public function warning(string $message, array $context = []): void;

    public function error(string $message, array $context = []): void;
}

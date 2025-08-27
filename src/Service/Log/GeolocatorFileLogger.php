<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Log;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

class GeolocatorFileLogger implements GeolocatorLoggerInterface
{
    private Logger $logger;

    public function __construct(string $logDir, string $channel = 'geolocator', string $level = 'warning')
    {
        $logPath      = rtrim($logDir, '/') . '/Geolocator.log';
        $this->logger = new Logger($channel);
        $allowed      = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        $levelNorm    = is_string($level) ? strtolower($level) : $level;
        if (is_string($levelNorm) && !in_array($levelNorm, $allowed, true)) {
            $levelNorm = 'warning';
        }
        $handler = new RotatingFileHandler($logPath, 7, Logger::toMonologLevel($levelNorm));
        $this->logger->pushHandler($handler);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }
}

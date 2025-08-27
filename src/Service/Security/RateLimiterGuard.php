<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Security;

use Neox\FireGeolocatorBundle\Service\Log\GeolocatorLoggerInterface;

/**
 * Lightweight guard around Symfony RateLimiter (optional).
 *
 * - If the RateLimiter component or configured limiter is not available, allow() always returns true.
 * - If a factory or limiter is injected, it will attempt to consume tokens and return acceptance.
 */
final class RateLimiterGuard
{
    /**
     * @param mixed $factory Optional RateLimiterFactory or Limiter or callable; null when not configured
     */
    public function __construct(
        private $factory = null,
        private ?GeolocatorLoggerInterface $logger = null,
        private string $limiterName = 'geolocator'
    ) {
    }

    public function allow(string $key, int $tokens = 1): bool
    {
        try {
            if ($this->factory === null) {
                // No limiter configured, allow by default
                return true;
            }

            $limiter = null;
            $f       = $this->factory;

            // If it's a callable that yields a limiter when passed the key
            if (is_callable($f)) {
                $limiter = $f($key);
            }
            // If it looks like a RateLimiterFactory (has create())
            elseif (is_object($f) && method_exists($f, 'create')) {
                $limiter = $f->create($key);
            }
            // If it's already a limiter (has consume())
            elseif (is_object($f) && method_exists($f, 'consume')) {
                $limiter = $f;
            }

            if ($limiter === null) {
                // Unknown type, allow by default to avoid breaking prod
                return true;
            }

            // Consume tokens
            $result = $limiter->consume($tokens);
            // RateLimit object normally has isAccepted(); be defensive if not
            if (is_object($result) && method_exists($result, 'isAccepted')) {
                $accepted = (bool) $result->isAccepted();
            } else {
                // If the limiter returns a boolean directly
                $accepted = (bool) $result;
            }

            if (!$accepted && $this->logger) {
                $this->logger->warning('Rate limit exceeded', [
                    'bucket'  => $key,
                    'limiter' => $this->limiterName,
                ]);
            }

            return $accepted;
        } catch (\Throwable $e) {
            // Fail-open: do not block in case of limiter errors
            if ($this->logger) {
                $this->logger->error('RateLimiterGuard error', [
                    'error'   => $e->getMessage(),
                    'bucket'  => $key,
                    'limiter' => $this->limiterName,
                ]);
            }

            return true;
        }
    }
}

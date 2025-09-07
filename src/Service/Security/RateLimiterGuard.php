<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Security;

use Neox\FireGeolocatorBundle\Service\Cache\StorageInterface;
use Neox\FireGeolocatorBundle\Service\Log\GeolocatorLoggerInterface;

/**
 * Lightweight guard around Symfony RateLimiter (optional) with default fallback.
 *
 * Behaviour:
 * - If the Symfony RateLimiter factory/limiter is available, use it.
 * - Otherwise, apply a simple fixed-window fallback limiter using StorageInterface
 *   with configurable window TTL and limit. This keeps protection active even
 *   without installing the RateLimiter component, while respecting privacy since
 *   the caller passes an anonymized bucket key.
 */
final class RateLimiterGuard
{
    /**
     * @param mixed $factory Optional RateLimiterFactory or Limiter or callable; null when not configured
     */
    public function __construct(
        private $factory = null,
        private ?GeolocatorLoggerInterface $logger = null,
        private string $limiterName = 'geolocator',
        private ?StorageInterface $storage = null,
        private int $fallbackLimit = 60,
        private int $fallbackWindowTtl = 60,
    ) {
    }

    public function allow(string $key, int $tokens = 1): bool
    {
        try {
            // Prefer Symfony RateLimiter when available
            if ($this->factory !== null) {
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

                if ($limiter !== null) {
                    $result   = $limiter->consume($tokens);
                    $accepted = is_object($result) && method_exists($result, 'isAccepted')
                        ? (bool) $result->isAccepted()
                        : (bool) $result;

                    if (!$accepted && $this->logger) {
                        $this->logger->warning('Rate limit exceeded', [
                            'bucket'  => $key,
                            'limiter' => $this->limiterName,
                            'mode'    => 'symfony',
                        ]);
                    }

                    return $accepted;
                }
                // Unknown factory type -> fallback
            }

            // Fallback limiter using generic storage attempts counter with optional per-bucket override
            if ($this->storage instanceof StorageInterface && $this->fallbackLimit > 0 && $this->fallbackWindowTtl > 0) {
                $limit     = $this->fallbackLimit;
                $windowTtl = $this->fallbackWindowTtl;
                try {
                    // Override key uses the full bucket (already contains algo version and anonymized id)
                    $ovrKey = 'rl_override:' . $key;
                    $ovr    = $this->storage->get($ovrKey);
                    if (is_array($ovr)) {
                        if (isset($ovr['limit']) && is_int($ovr['limit']) && $ovr['limit'] > 0) {
                            $limit = $ovr['limit'];
                        }
                        if (isset($ovr['window_ttl']) && is_int($ovr['window_ttl']) && $ovr['window_ttl'] > 0) {
                            $windowTtl = $ovr['window_ttl'];
                        }
                    }
                } catch (\Throwable) {
                    // Ignore override errors and use defaults
                }

                $count    = $this->storage->incrementAttempts($key, $windowTtl);
                $accepted = $count <= $limit;
                if (!$accepted && $this->logger) {
                    $this->logger->warning('Rate limit exceeded (fallback)', [
                        'bucket'  => $key,
                        'limiter' => $this->limiterName,
                        'mode'    => 'fallback_fixed_window',
                        'limit'   => $limit,
                        'ttl'     => $windowTtl,
                    ]);
                }

                return $accepted;
            }

            // If no limiter and no storage configured, fail-open
            return true;
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

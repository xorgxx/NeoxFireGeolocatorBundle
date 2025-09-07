<?php

namespace Neox\FireGeolocatorBundle\Service\Privacy;

final class HmacIpAnonymizer implements IpAnonymizerInterface
{
    public function __construct(
        private string $secret,
        private int $truncate = 32,
    ) {
        if ($this->secret === '') {
            throw new \RuntimeException('GEO_HASH_SECRET is required');
        }
    }

    public function anonymize(string $ip): string
    {
        $norm = self::normalizeIp($ip);
        $hmac = hash_hmac('sha256', $norm, $this->secret);

        return substr($hmac, 0, max(8, $this->truncate));
    }

    public static function normalizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed !== false) {
                $canon = @inet_ntop($packed);
                if (is_string($canon)) {
                    return $canon;
                }
            }
        }

        return (string) $ip;
    }
}

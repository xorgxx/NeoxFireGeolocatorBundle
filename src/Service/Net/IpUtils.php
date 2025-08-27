<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Net;

final class IpUtils
{
    /**
     * Normalize a potential IP token found in proxy headers by trimming, removing quotes and brackets.
     */
    public static function normalizeToken(string $token): string
    {
        $t = trim($token);
        // remove surrounding quotes
        if ((str_starts_with($t, '"') && str_ends_with($t, '"')) || (str_starts_with($t, "'") && str_ends_with($t, "'"))) {
            $t = substr($t, 1, -1);
        }
        // remove IPv6 square brackets [::1]
        if (str_starts_with($t, '[') && str_ends_with($t, ']')) {
            $t = substr($t, 1, -1);
        }

        return trim($t);
    }

    /**
     * Return true if the IP is invalid or belongs to private/reserved ranges.
     */
    public static function isPrivateOrLoopback(string $ip): bool
    {
        // CHANGEMENT: ne plus considérer les "reserved ranges" comme privées,
        // mais détecter explicitement loopback et privées (RFC1918/ULA/link-local).
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        // IPv4 loopback 127.0.0.0/8
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (str_starts_with($ip, '127.')) {
                return true;
            }
            // RFC1918 private ranges
            if (str_starts_with($ip, '10.')) {
                return true;
            }
            if (preg_match('/^192\.168\./', $ip) === 1) {
                return true;
            }
            // 172.16.0.0 – 172.31.255.255
            if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip) === 1) {
                return true;
            }
            // Optionnel: CGNAT 100.64.0.0/10 (souvent non public)
            if (preg_match('/^100\.(6[4-9]|[7-9][0-9]|1[0-1][0-9]|12[0-7])\./', $ip) === 1) {
                return true;
            }

            return false;
        }

        // IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            // Loopback
            if ($lower === '::1') {
                return true;
            }
            // Link-local fe80::/10
            if (preg_match('/^fe8[0-9a-f]:|^fe9[0-9a-f]:|^fea[0-9a-f]:|^feb[0-9a-f]:/i', $lower) === 1) {
                return true;
            }
            // Unique local fc00::/7 (fc00::/8 et fd00::/8)
            if (preg_match('/^(fc|fd)[0-9a-f]{2}:/i', $lower) === 1) {
                return true;
            }

            // Ne PAS classer 2001:db8::/32 comme privée: elle sera donc considérée "publique" ici
            return false;
        }

        return true;
    }

    /**
     * Parse an X-Forwarded-For or similar header and return the first public IP if available,
     * else the first valid IP found.
     */
    public static function pickClientIpFromForwarded(string $headerValue): ?string
    {
        // Split on comma, semicolon or whitespace sequences
        $parts      = preg_split('/\s*[;,]\s*|\s+/', $headerValue) ?: [];
        $firstValid = null;
        foreach ($parts as $raw) {
            if ($raw === '') {
                continue;
            }
            $cand = self::normalizeToken($raw);
            if (filter_var($cand, FILTER_VALIDATE_IP)) {
                if ($firstValid === null) {
                    $firstValid = $cand;
                }
                if (!self::isPrivateOrLoopback($cand)) {
                    return $cand; // return first public IP
                }
            }
        }

        return $firstValid; // may be private if nothing else
    }

    /**
     * For single-IP headers (X-Real-IP, CF-Connecting-IP...), normalize and validate.
     */
    public static function extractSingleIp(string $value): ?string
    {
        $cand = self::normalizeToken($value);

        return filter_var($cand, FILTER_VALIDATE_IP) ? $cand : null;
    }
}

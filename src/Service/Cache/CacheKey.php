<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Cache;

final class CacheKey
{
    public const PREFIX_CTX        = 'ctx:';        // contexte géo par provider
    public const PREFIX_GEO_IPINFO = 'geo:ipinfo:'; // orchestration ipinfo
    public const PREFIX_EXC        = 'exc:';        // exclusions temporaires
    public const PREFIX_BAN        = 'ban:';        // bannissements / buckets
    public const PREFIX_CLI        = 'cli:';        // empreinte client

    public static function ctx(string $provider, string $ip): string
    {
        return self::PREFIX_CTX . $provider . ':' . $ip;
    }
    // ... existing code ...

    // Nouvelle clé de contexte basée sur l'ID de session
    public static function ctxSession(string $provider, string $sessionId): string
    {
        return self::PREFIX_CTX . $provider . ':sess:' . $sessionId;
    }

    public static function geoIpinfo(string $ip): string
    {
        return self::PREFIX_GEO_IPINFO . $ip;
    }

    public static function exc(string $type, string $id): string
    {
        return self::PREFIX_EXC . $type . ':' . $id;
    }

    public static function ban(string $bucket): string
    {
        return self::PREFIX_BAN . $bucket;
    }

    public static function cli(string $hash): string
    {
        return self::PREFIX_CLI . $hash;
    }
}

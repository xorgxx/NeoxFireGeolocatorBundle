<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;

final class RuleHelper
{
    public static function ipMatches(string $ip, string $rule): bool
    {
        if (str_contains($rule, '/')) {
            [$subnet, $mask] = explode('/', $rule, 2);

            return self::cidrMatch($ip, $subnet, (int) $mask);
        }

        return $ip === $rule;
    }

    public static function ipWhitelisted(array $filters, string $ip): bool
    {
        $ipFilter = $filters['ip'] ?? null;
        if (!is_array($ipFilter)) {
            return false;
        }
        $rules      = isset($ipFilter['rules']) && is_array($ipFilter['rules']) ? $ipFilter['rules'] : [];
        $allowIps   = [];
        $allowCidrs = [];
        foreach ($rules as $rule) {
            if (!is_string($rule) || $rule === '') {
                continue;
            }
            $sign = $rule[0];
            $val  = ltrim($rule, '+-');
            if ($sign === '+') {
                if (str_contains($val, '/')) {
                    $allowCidrs[] = $val;
                } else {
                    $allowIps[] = $val;
                }
            }
        }
        if (in_array($ip, $allowIps, true)) {
            return true;
        }
        foreach ($allowCidrs as $cidr) {
            if (self::cidrContains($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function cidrContains(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        if ($subnet === '' || $mask === '') {
            return false;
        }

        return self::cidrMatch($ip, $subnet, (int) $mask);
    }

    public static function cidrMatch(string $ip, string $subnet, int $mask): bool
    {
        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        $len = strlen($ipBin);
        if ($len !== strlen($subnetBin)) {
            return false;
        }
        $maxBits = $len * 8;
        if ($mask < 0 || $mask > $maxBits) {
            return false;
        }
        $fullBytes = intdiv($mask, 8);
        $remBits   = $mask % 8;
        $maskBytes = str_repeat("\xFF", $fullBytes);
        if ($remBits > 0) {
            $maskBytes .= chr((0xFF << (8 - $remBits)) & 0xFF);
        }
        if (strlen($maskBytes) < $len) {
            $maskBytes .= str_repeat("\x00", $len - strlen($maskBytes));
        }
        $ipNet  = $ipBin     & $maskBytes;
        $subNet = $subnetBin & $maskBytes;

        return $ipNet === $subNet;
    }

    public static function navigatorMatch(string $ua, string $pattern): bool
    {
        $ua = (string) $ua;
        if (strlen($pattern) >= 2 && $pattern[0] === '/' && strrpos($pattern, '/') > 0) {
            $last  = strrpos($pattern, '/');
            $expr  = substr($pattern, 1, $last - 1);
            $mod   = substr($pattern, $last + 1);
            $regex = '/' . $expr . '/' . $mod;

            return @preg_match($regex, $ua) === 1;
        }
        $p = (string) $pattern;
        if ($p !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $p)) {
            return (bool) preg_match('/\\b' . preg_quote($p, '/') . '\\b/i', $ua);
        }

        return stripos($ua, $p) !== false;
    }

    public static function isKnownCrawler(string $ua): bool
    {
        $ua = strtolower($ua);
        foreach ([
            'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot', 'sogou',
            'exabot', 'facebot', 'facebookexternalhit', 'ia_archiver', 'linkedinbot', 'twitterbot',
            'whatsapp', 'telegrambot', 'slackbot', 'discordbot', 'bingpreview', 'crawler', 'spider', 'bot',
        ] as $sig) {
            if ($sig !== '' && str_contains($ua, $sig)) {
                return true;
            }
        }

        return false;
    }

    public static function looksLikeCrawler(string $ua): bool
    {
        $ua = strtolower($ua);
        foreach (['bot', 'spider', 'crawler', 'slurp', 'bingpreview'] as $sig) {
            if (str_contains($ua, $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sépare les règles signées en listes allow/deny (sans le signe).
     *
     * @return array{allow:string[],deny:string[]}
     */
    public static function splitSignedRules(array $rules): array
    {
        $out = ['allow' => [], 'deny' => []];
        foreach ($rules as $rule) {
            if (!is_string($rule) || $rule === '') {
                continue;
            }
            $sign = $rule[0];
            $val  = ltrim($rule, '+-');
            if ($val === '') {
                continue;
            }
            if ($sign === '+') {
                $out['allow'][] = $val;
            } elseif ($sign === '-') {
                $out['deny'][] = $val;
            }
        }

        return $out;
    }

    /**
     * Retourne le premier motif qui matche subject parmi patterns à l'aide d'un matcher.
     * $matcher(string $subject, string $pattern): bool.
     */
    public static function firstMatch(string $subject, array $patterns, callable $matcher): ?string
    {
        foreach ($patterns as $p) {
            if (!is_string($p) || $p === '') {
                continue;
            }
            try {
                if ($matcher($subject, $p)) {
                    return $p;
                }
            } catch (\Throwable) {
                // ignorer un motif invalide
            }
        }

        return null;
    }

    /**
     * Évalue des règles IP avec la sémantique standard:
     * - allow-list ('+') prioritaire: autorise explicitement si match
     * - deny-list ('-') ensuite: bloque si match
     * - sinon, applique defaultBehavior ('allow' ou 'block')
     *
     * Retourne null si l'évaluation n'impose pas de blocage explicite (cas "allow").
     * Retourne AuthorizationDTO(false, ...) si un blocage est décidé.
     */
    public static function evaluateIpRules(string $ip, array $rules, string $defaultBehavior): ?AuthorizationDTO
    {
        $signed = self::splitSignedRules($rules);

        // 1) Whitelist prioritaire
        $allowHit = self::firstMatch($ip, $signed['allow'], [self::class, 'ipMatches']);
        if ($allowHit !== null) {
            // autorisation explicite -> pas de blocage
            return null;
        }

        // 2) Blacklist
        $denyHit = self::firstMatch($ip, $signed['deny'], [self::class, 'ipMatches']);
        if ($denyHit !== null) {
            return new AuthorizationDTO(false, 'Denied by ip:' . $denyHit, 'ip:' . $denyHit);
        }

        // 3) Défaut
        $defaultAllow = (strtolower($defaultBehavior) === 'allow');
        if ($defaultAllow) {
            return null;
        }

        return new AuthorizationDTO(false, 'Denied by ip:default', 'ip:default');
    }
}

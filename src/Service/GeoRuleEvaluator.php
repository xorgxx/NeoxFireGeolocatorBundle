<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;

/**
 * Rule evaluation precedence:
 * 1) IP whitelist (+ in filters.ip.rules) always ALLOW and short-circuits.
 * 2) VPN check (if enabled): when detected and default_behavior=block, deny.
 * 3) Category rules evaluation in order: navigator, country, ip, crawler.
 *    - First matching '-' denies immediately with reason.
 *    - '+' marks allowed for that category; if no rule matches, category default_behavior applies.
 * 4) If all categories allow, final decision is allow.
 *
 * IPv6: CIDR and exact matches are supported via inet_pton; IPv4 supported as well.
 * Navigator: supports regex rules like /pattern/i and word-boundary matching for plain tokens.
 */
class GeoRuleEvaluator
{
    public function evaluate(array $filters, array $facts): AuthorizationDTO
    {
        // Short-circuit: IP whitelist with precedence rules
        // - Exact allow beats CIDR deny
        // - Exact deny beats CIDR allow
        $ip = (string) ($facts['ip'] ?? '');
        if ($ip !== '' && isset($filters['ip']) && is_array($filters['ip'])) {
            $ipFilter = $filters['ip'];
            $rules    = isset($ipFilter['rules']) && is_array($ipFilter['rules']) ? $ipFilter['rules'] : [];

            $exactAllow = false;
            $cidrAllow  = false;
            $exactDeny  = false;
            $cidrDeny   = false;

            foreach ($rules as $rule) {
                if (!is_string($rule) || $rule === '') {
                    continue;
                }
                $sign   = $rule[0];
                $val    = ltrim($rule, '+-');
                $isCidr = strpos($val, '/') !== false;

                if ($sign === '+') {
                    if ($isCidr && $this->cidrContains($ip, $val)) {
                        $cidrAllow = true;
                    }
                    if (!$isCidr && $ip === $val) {
                        $exactAllow = true;
                    }
                } elseif ($sign === '-') {
                    if ($isCidr && $this->cidrContains($ip, $val)) {
                        $cidrDeny = true;
                    }
                    if (!$isCidr && $ip === $val) {
                        $exactDeny = true;
                    }
                }
            }

            if ($exactAllow) {
                // Exact allow wins even if a broader CIDR deny matches
                return new AuthorizationDTO(true, 'ip whitelist', null);
            }
            if ($cidrAllow) {
                // If an exact deny also matches, deny takes precedence
                if ($exactDeny) {
                    $reason = 'ip:rule';

                    return new AuthorizationDTO(false, $reason, $reason);
                }

                return new AuthorizationDTO(true, 'ip whitelist', null);
            }
            // No allow match -> continue normal evaluation
        }

        // Special-case VPN/Proxy detection: only act when detected and enabled.
        $vpnCfg = $filters['vpn'] ?? null;
        if (is_array($vpnCfg) && (($vpnCfg['enabled'] ?? false) === true)) {
            $isVpn = (bool) ($facts['isVpn'] ?? false);
            if ($isVpn) {
                $defaultBehavior = $vpnCfg['default_behavior'] ?? 'allow';
                if ($defaultBehavior !== 'allow') {
                    $reason = 'vpn:detected';

                    return new AuthorizationDTO(false, $reason, $reason);
                }
            }
        }

        $denyReason = null;
        foreach (['navigator', 'country', 'ip', 'crawler'] as $cat) {
            // Skip crawler category entirely if disabled in config
            if ($cat === 'crawler') {
                $crawlerCfg = $filters['crawler'] ?? [];
                if (is_array($crawlerCfg) && (($crawlerCfg['enabled'] ?? true) === false)) {
                    continue;
                }
            }

            $rules    = $filters[$cat]['rules'] ?? [];
            $default  = ($filters[$cat]['default_behavior'] ?? 'allow') === 'allow';
            $decision = $default;

            // Crawler: baseline allow if allow_known=true and UA is a known crawler (unless a '-' rule matches later)
            if ($cat === 'crawler') {
                $ua            = (string) ($facts['ua'] ?? '');
                $allowKnown    = (bool) ($filters['crawler']['allow_known'] ?? true);
                $isCrawlerFlag = (bool) ($facts['isCrawler'] ?? false);

                // Si détecté comme crawler, partir d'un état "deny" par défaut,
                // puis laisser les règles (+) ou allow_known renverser vers "allow".
                if ($isCrawlerFlag) {
                    $decision = false;
                }

                if ($allowKnown && $this->isKnownCrawler($ua)) {
                    $decision = true;
                }
            }

            foreach ($rules as $rule) {
                $sign  = substr($rule, 0, 1);
                $value = substr($rule, 1);
                $match = false;
                if ($cat === 'navigator') {
                    $ua    = (string) ($facts['ua'] ?? '');
                    $match = $this->navigatorMatch($ua, $value);
                } elseif ($cat === 'country') {
                    $match = strtoupper((string) ($facts['countryCode'] ?? '')) === strtoupper($value);
                } elseif ($cat === 'ip') {
                    $match = $this->ipMatch((string) ($facts['ip'] ?? ''), $value);
                } elseif ($cat === 'crawler') {
                    $ua       = (string) ($facts['ua'] ?? '');
                    $valLower = strtolower($value);
                    if ($valLower === 'known') {
                        $match = (bool) ($filters['crawler']['allow_known'] ?? true) && $this->isKnownCrawler($ua);
                    } else {
                        // réutilise la logique de matching navigator (regex, tokens, etc.)
                        $match = $this->navigatorMatch($ua, $value);
                    }
                }
                if ($match) {
                    if ($sign === '-') {
                        $denyReason = $cat . ':rule';
                        $decision   = false;
                        break;
                    }
                    if ($sign === '+') {
                        $decision = true;
                    }
                }
            }
            if ($denyReason !== null) {
                return new AuthorizationDTO(false, $denyReason, $denyReason);
            }
            if (!$decision) {
                $denyReason = $cat . ':default';

                return new AuthorizationDTO(false, $denyReason, $denyReason);
            }
        }

        return new AuthorizationDTO(true, null, null);
    }

    private function ipMatch(string $ip, string $rule): bool
    {
        if (str_contains($rule, '/')) {
            [$subnet, $mask] = explode('/', $rule, 2);

            return $this->cidrMatch($ip, $subnet, (int) $mask);
        }

        return $ip === $rule;
    }

    private function cidrContains(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        if ($subnet === '' || $mask === '') {
            return false;
        }

        return $this->cidrMatch($ip, $subnet, (int) $mask);
    }

    private function cidrMatch(string $ip, string $subnet, int $mask): bool
    {
        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        // Families must match (4 bytes for IPv4, 16 bytes for IPv6)
        $len = strlen($ipBin);
        if ($len !== strlen($subnetBin)) {
            return false;
        }
        $maxBits = $len * 8;
        if ($mask < 0 || $mask > $maxBits) {
            return false;
        }
        // Build mask bytes
        $fullBytes = intdiv($mask, 8);
        $remBits   = $mask % 8;
        $maskBytes = str_repeat("\xFF", $fullBytes);
        if ($remBits > 0) {
            $maskBytes .= chr((0xFF << (8 - $remBits)) & 0xFF);
        }
        if (strlen($maskBytes) < $len) {
            $maskBytes .= str_repeat("\x00", $len - strlen($maskBytes));
        }
        // Compare network portions
        $ipNet  = $ipBin     & $maskBytes;
        $subNet = $subnetBin & $maskBytes;

        return $ipNet === $subNet;
    }

    private function navigatorMatch(string $ua, string $pattern): bool
    {
        $ua = (string) $ua;
        // Regex rule like /pattern/i or /pattern/
        if (strlen($pattern) >= 2 && $pattern[0] === '/' && strrpos($pattern, '/') > 0) {
            $last  = strrpos($pattern, '/');
            $expr  = substr($pattern, 1, $last - 1);
            $mod   = substr($pattern, $last + 1);
            $delim = '/';
            $regex = $delim . $expr . $delim . $mod;

            return @preg_match($regex, $ua) === 1;
        }
        $p = (string) $pattern;
        // Word-boundary match for alnum-ish tokens, else fallback to case-insensitive substring
        if ($p !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $p)) {
            return (bool) preg_match('/\\b' . preg_quote($p, '/') . '\\b/i', $ua);
        }

        return stripos($ua, $p) !== false;
    }

    private function isKnownCrawler(string $ua): bool
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
}

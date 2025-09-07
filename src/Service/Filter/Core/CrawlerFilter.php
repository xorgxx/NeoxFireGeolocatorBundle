<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Filter\Core;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Filter\AbstractFilter;
use Neox\FireGeolocatorBundle\Service\Filter\RuleHelper;
use Symfony\Component\HttpFoundation\Request;

final class CrawlerFilter extends AbstractFilter
{
    public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
    {
        /** @var ResolvedGeoApiConfigDTO|null $cfg */
        $cfg = $request->attributes->get('geolocator_config');
        if (!$cfg instanceof ResolvedGeoApiConfigDTO) {
            return null;
        }
        $crawlerCfg = $cfg->filters['crawler'] ?? null;
        if (!is_array($crawlerCfg)) {
            return null;
        }
        if (($crawlerCfg['enabled'] ?? true) === false) {
            return null;
        }

        $ua           = (string) $request->headers->get('User-Agent', '');
        $rules        = is_array($crawlerCfg['rules'] ?? null) ? $crawlerCfg['rules'] : [];
        $defaultAllow = (($crawlerCfg['default_behavior'] ?? 'allow') === 'allow');
        $allowKnown   = (bool) ($crawlerCfg['allow_known'] ?? true);

        // Baseline: heuristique + allow_known
        $decision = $defaultAllow;
        if (RuleHelper::looksLikeCrawler($ua)) {
            $decision = false; // baseline deny si "bot-like"
        }
        if ($allowKnown && RuleHelper::isKnownCrawler($ua)) {
            $decision = true;  // override allow si "known" autorisé
        }

        $signed       = RuleHelper::splitSignedRules($rules);
        $uaMatcher    = static fn (string $subject, string $pattern): bool => RuleHelper::navigatorMatch($subject, $pattern);
        $knownMatcher = static fn (string $subject, string $pattern) => strtolower($pattern) === 'known' && RuleHelper::isKnownCrawler($subject);

        // 1) Deny prioritaire
        foreach ($signed['deny'] as $pattern) {
            if ($pattern === '') {
                continue;
            }
            $matched = strtolower($pattern) === 'known' ? ($allowKnown && $knownMatcher($ua, $pattern)) : $uaMatcher($ua, $pattern);
            if ($matched) {
                $reason = 'crawler rule';

                return new AuthorizationDTO(false, $reason, $reason);
            }
        }

        // 2) Allow explicite
        foreach ($signed['allow'] as $pattern) {
            if ($pattern === '') {
                continue;
            }
            $matched = strtolower($pattern) === 'known' ? ($allowKnown && $knownMatcher($ua, $pattern)) : $uaMatcher($ua, $pattern);
            if ($matched) {
                return null;
            }
        }

        // 3) Défaut / baseline
        return $decision ? null : new AuthorizationDTO(false, 'crawler:default', 'crawler:default');
    }
}

<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Filter\Core;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Filter\AbstractFilter;
use Neox\FireGeolocatorBundle\Service\Filter\RuleHelper;
use Symfony\Component\HttpFoundation\Request;

final class NavigatorFilter extends AbstractFilter
{
    public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
    {
        /** @var ResolvedGeoApiConfigDTO|null $cfg */
        $cfg = $request->attributes->get('geolocator_config');
        if (!$cfg instanceof ResolvedGeoApiConfigDTO) {
            return null;
        }
        $navCfg = $cfg->filters['navigator'] ?? null;
        if (!is_array($navCfg)) {
            return null;
        }
        $ua           = (string) $request->headers->get('User-Agent', '');
        $rules        = is_array($navCfg['rules'] ?? null) ? $navCfg['rules'] : [];
        $defaultAllow = (($navCfg['default_behavior'] ?? 'allow') === 'allow');

        $signed    = RuleHelper::splitSignedRules($rules);
        $uaMatcher = static fn (string $subject, string $pattern): bool => RuleHelper::navigatorMatch($subject, $pattern);

        // Deny prioritaire
        $denyHit = RuleHelper::firstMatch($ua, $signed['deny'], $uaMatcher);
        if ($denyHit !== null) {
            $reason = 'navigator rule';

            return new AuthorizationDTO(false, $reason, $reason);
        }

        // Allow explicite
        $allowHit = RuleHelper::firstMatch($ua, $signed['allow'], $uaMatcher);
        if ($allowHit !== null) {
            return null;
        }

        // Défaut
        return $defaultAllow ? null : new AuthorizationDTO(false, 'navigator:default', 'navigator:default');
    }
}

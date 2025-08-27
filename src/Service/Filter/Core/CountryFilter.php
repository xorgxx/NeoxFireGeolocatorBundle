<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Filter\Core;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Filter\AbstractFilter;
use Neox\FireGeolocatorBundle\Service\Filter\RuleHelper;
use Symfony\Component\HttpFoundation\Request;

final class CountryFilter extends AbstractFilter
{
    public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
    {
        /** @var ResolvedGeoApiConfigDTO|null $cfg */
        $cfg = $request->attributes->get('geolocator_config');
        if (!$cfg instanceof ResolvedGeoApiConfigDTO) {
            return null;
        }
        $countryCfg = $cfg->filters['country'] ?? null;
        if (!is_array($countryCfg)) {
            return null;
        }
        $defaultAllow = (($countryCfg['default_behavior'] ?? 'allow') === 'allow');
        $rules        = is_array($countryCfg['rules'] ?? null) ? $countryCfg['rules'] : [];

        $code = strtoupper((string) ($ctx?->countryCode ?? ''));
        if ($code === '') {
            return $defaultAllow ? null : new AuthorizationDTO(false, 'Denied by country:default', 'country:default');
        }

        $signed = RuleHelper::splitSignedRules($rules);
        $eqCI   = static fn (string $subject, string $pattern): bool => strtoupper($subject) === strtoupper($pattern);

        // Deny prioritaire
        $denyHit = RuleHelper::firstMatch($code, $signed['deny'], $eqCI);
        if ($denyHit !== null) {
            $reason = 'country:' . strtoupper($denyHit);

            return new AuthorizationDTO(false, 'Denied by ' . $reason, $reason);
        }

        // Allow explicite
        $allowHit = RuleHelper::firstMatch($code, $signed['allow'], $eqCI);
        if ($allowHit !== null) {
            return null;
        }

        // Défaut
        return $defaultAllow ? null : new AuthorizationDTO(false, 'Denied by country:default', 'country:default');
    }
}

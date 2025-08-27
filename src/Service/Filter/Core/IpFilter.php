<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Filter\Core;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Filter\AbstractFilter;
use Neox\FireGeolocatorBundle\Service\Filter\RuleHelper;
use Symfony\Component\HttpFoundation\Request;

final class IpFilter extends AbstractFilter
{
    public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
    {
        /** @var ResolvedGeoApiConfigDTO|null $cfg */
        $cfg = $request->attributes->get('geolocator_config');
        if (!$cfg instanceof ResolvedGeoApiConfigDTO) {
            return null;
        }
        $filters = $cfg->filters;
        $ipCfg   = $filters['ip'] ?? null;
        if (!is_array($ipCfg)) {
            return null;
        }
        $ip = $ctx?->ip ?? ($request->getClientIp() ?: '');
        if ($ip === '') {
            return null;
        }

        // Whitelist first: explicit allow and short-circuit
        if (RuleHelper::ipWhitelisted($filters, $ip)) {
            return new AuthorizationDTO(true, 'ip whitelist', 'ip:whitelist');
        }

        // 2) Évaluation des règles via RuleHelper (remplace les méthodes manquantes)
        $rules           = is_array($ipCfg['rules'] ?? null) ? $ipCfg['rules'] : [];
        $defaultBehavior = (string) ($ipCfg['default_behavior'] ?? 'allow');
        $decision        = RuleHelper::evaluateIpRules($ip, $rules, $defaultBehavior);
        if ($decision instanceof AuthorizationDTO) {
            return $decision;
        }

        // 3) Si aucune règle n’impose un blocage, on autorise (null)
        return null;
    }
}

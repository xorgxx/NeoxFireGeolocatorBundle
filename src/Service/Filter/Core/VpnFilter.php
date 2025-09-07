<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Filter\Core;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ResolvedGeoApiConfigDTO;
use Neox\FireGeolocatorBundle\Service\Filter\AbstractFilter;
use Symfony\Component\HttpFoundation\Request;

final class VpnFilter extends AbstractFilter
{
    public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
    {
        /** @var ResolvedGeoApiConfigDTO|null $cfg */
        $cfg = $request->attributes->get('geolocator_config');
        if (!$cfg instanceof ResolvedGeoApiConfigDTO) {
            return null;
        }
        $vpnCfg = $cfg->filters['vpn'] ?? null;
        if (!is_array($vpnCfg) || (($vpnCfg['enabled'] ?? false) !== true)) {
            return null;
        }
        $isVpn = (bool) (($ctx?->proxy ?? false) || ($ctx?->hosting ?? false));
        if (!$isVpn) {
            return null;
        }

        $defaultBehavior = strtolower((string) ($vpnCfg['default_behavior'] ?? 'allow'));
        if ($defaultBehavior !== 'allow') {
            $reason = 'vpn detected';

            return new AuthorizationDTO(false, $reason, $reason);
        }

        return null;
    }
}

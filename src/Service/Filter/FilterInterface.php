<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Symfony\Component\HttpFoundation\Request;

interface FilterInterface
{
    // true = actif; false = ignoré
    public function isEnabled(): bool;

    // Retourne:
    // - AuthorizationDTO (allowed=false) pour refuser immédiatement
    // - AuthorizationDTO (allowed=true) pour autoriser explicitement (rare; la plupart des filtres retournent null en “pass”)
    // - null si non concerné (laisser la chaîne continuer)
    public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO;
}

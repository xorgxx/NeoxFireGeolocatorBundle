<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class GeolocatorVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'GEOLOC_ALLOWED';
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // Delegated to request listener decisions; voter provided for completeness.
        return true;
    }
}

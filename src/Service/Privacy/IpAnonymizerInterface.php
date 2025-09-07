<?php

namespace Neox\FireGeolocatorBundle\Service\Privacy;

interface IpAnonymizerInterface
{
    public function anonymize(string $ip): string;
}

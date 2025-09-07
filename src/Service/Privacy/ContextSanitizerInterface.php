<?php

namespace Neox\FireGeolocatorBundle\Service\Privacy;

interface ContextSanitizerInterface
{
    public function sanitize(array|object $ctx): array;
}

<?php

namespace Neox\FireGeolocatorBundle\Service\Privacy;

final class DefaultContextSanitizer implements ContextSanitizerInterface
{
    /** @param string[] $fieldsToRemove */
    public function __construct(private array $fieldsToRemove = [])
    {
    }

    public function sanitize(array|object $ctx): array
    {
        $arr    = is_object($ctx) ? (array) json_decode(json_encode($ctx), true) : $ctx;
        $remove = $this->fieldsToRemove ?: [
            'ip', 'city', 'latitude', 'longitude', 'lat', 'lon', 'preciseLocation', 'userAgent', 'ua',
        ];
        foreach ($remove as $f) {
            unset($arr[$f]);
        }

        return $arr;
    }
}

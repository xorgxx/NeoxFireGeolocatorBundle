<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Service\Filter;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;

class FilterChain
{
    /**
     * @param iterable<FilterInterface> $filters
     */
    public function __construct(
        #[AutowireIterator('xorgxx.geolocator.filter')]
        private readonly iterable $filters = []
    ) {
    }

    /**
     * Runs the filters (core + custom) and returns the first denial encountered,
     * or an explicit allow, or null if none decides.
     */
    public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
    {
        $allow = null;
        foreach ($this->filters as $filter) {
            if (!$filter->isEnabled()) {
                continue;
            }
            try {
                $res = $filter->decide($request, $ctx);
            } catch (\Throwable) {
                // Safety: a filter must not bring down the entire chain
                continue;
            }
            if ($res === null) {
                continue;
            }
            if ($res->allowed === false) {
                return $res; // first denial -> stop
            }
            if ($res->allowed === true) {
                $allow = $res; // mémoriser un allow explicite (au cas où aucun refus n’arrive)
            }
        }

        return $allow; // peut être null
    }
}

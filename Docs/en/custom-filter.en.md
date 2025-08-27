# Create a custom filter (EN)

## Contract
- Interface: Neox\FireGeolocatorBundle\Service\Filter\FilterInterface
  - isEnabled(): bool
  - decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
- Use AbstractFilter for a ready-to-use base class.

## Service registration
- Tag: neox_fire_geolocator.filter
- Priority: higher runs earlier (e.g., 500 before IpFilter)

## Minimal example

```php
namespace App\Geolocator;

use Neox\FireGeolocatorBundle\DTO\AuthorizationDTO;
use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\Service\Filter\AbstractFilter;
use Symfony\Component\HttpFoundation\Request;

final class OfficeHoursFilter extends AbstractFilter
{
    public function __construct(private string $tz = 'Europe/Paris')
    {
        parent::__construct(true); // enabled
    }

    public function decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
    {
        $h = (new \DateTimeImmutable('now', new \DateTimeZone($this->tz)))->format('G');
        if ((int)$h < 8 || (int)$h > 20) {
            return new AuthorizationDTO(false, 'Denied by office_hours', 'custom:office_hours');
        }

        return null;
    }
}
```

```yaml
# services.yaml
App\Geolocator\OfficeHoursFilter:
  arguments: [ 'Europe/Paris' ]
  tags:
    - { name: 'neox_fire_geolocator.filter', priority: 50 }
```

## Accessing configuration
- Fetch the effective configuration from request attribute 'geolocator_config' (ResolvedGeoApiConfigDTO) if needed.
- You may also read headers/UA from Request.

## Testing & validation
- Use ?geo_simulate=1 to validate decisions without blocking.
- Add functional tests with controlled UA/IP and assert the blockingFilter of AuthorizationDTO.

# PHP Attributes (EN)

## GeoApi (class/method)
### Signature (main parameters):
```php
#[GeoApi(
  enabled: ?bool = null,
  simulate: ?bool = null,
  redirectOnBan: ?string = null,
  providerFallbackMode: ?bool = null,
  forceProvider: ?string = null,
  cacheTtl: ?int = null,
  blockOnError: ?bool = null,
  exclusionKey: ?string = null,
  cacheKeyStrategy: ?string = null, # 'ip'|'session'
  excludeRoutes: ?array = [],
  countries: ?array = [],
  ips: ?array = [],
  crawlers: ?array = [],
  vpnEnabled: ?bool = null,
  vpnDefaultBehavior: ?string = null, # 'allow'|'block'
  filters: ?array = null,
)]
```

## Scope and merge
- Target a controller class and/or an action method. The method attribute overrides the class; attributes are merged with the global configuration.
- Shortcuts countries/ips/crawlers feed into filters.country/ip/crawler.rules.

## Examples

```php
use Neox\FireGeolocatorBundle\Attribute\GeoApi;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/checkout')]
#[GeoApi(
  simulate: false,
  countries: ['+FR','+BE','-RU'],
  vpnEnabled: true,
  vpnDefaultBehavior: 'block',
  cacheKeyStrategy: 'session',
)]
final class CheckoutController
{
  #[Route('', name: 'checkout')]
  #[GeoApi(ips: ['+127.0.0.1'])]
  public function __invoke(): Response { /* ... */ }
}
```

## Priority/merge
- The action attribute overrides/refines the class attribute. Final config is exposed as ResolvedGeoApiConfigDTO and used by filters.

## Best practices
- Avoid simulate=true in production.
- Prefer shortcuts (countries/ips) for simple overrides; use filters for advanced cases.

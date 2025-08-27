# 🏷️ Attributs PHP (FR)

## 🔖 GeoApi (classe/méthode)
### ✍️ Signature (principaux paramètres):
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

## 🧭 Portée et fusion
- Cibler un contrôleur entier (classe) et/ou une action (méthode). L’action surcharge la classe; les attributs sont fusionnés avec la config globale.
- Les raccourcis `countries`/`ips`/`crawlers` alimentent `filters.country`/`ip`/`crawler.rules`.

## 🧪 Exemples

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

## 🧱 Priorité/merge
- L’attribut d’action complète/affine celui de la classe. La config finale est exposée en `ResolvedGeoApiConfigDTO` et lue par les filtres.

## ✅ Bonnes pratiques
- Évitez de définir `simulate=true` en prod.
- Préférez les raccourcis (`countries`/`ips`) pour des overrides simples; utilisez `filters` pour les cas avancés.

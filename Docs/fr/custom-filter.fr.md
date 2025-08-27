# 🧩 Créer un filtre personnalisé (FR)

## Contrat
- Interface: Neox\FireGeolocatorBundle\Service\Filter\FilterInterface
  - isEnabled(): bool
  - decide(Request $request, ?GeoApiContextDTO $ctx): ?AuthorizationDTO
- Utilisez AbstractFilter pour un squelette prêt-à-l’emploi.

## Enregistrement du service
- Tag: neox_fire_geolocator.filter
- Priorité: plus grand = exécuté plus tôt (ex: 500 avant IpFilter)

## Exemple minimal

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

## Accès à la configuration
- Récupérez la config effective via l’attribut de requête 'geolocator_config' (ResolvedGeoApiConfigDTO) si nécessaire.
- Vous pouvez aussi lire des headers/UA via Request.

## Tests et validation
- Simulez via ?geo_simulate=1 pour vérifier les décisions sans bloquer.
- Ajoutez des tests fonctionnels avec des UA/IP contrôlés et vérifiez le code blocant (AuthorizationDTO->blockingFilter).

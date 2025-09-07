# 📦 Providers & DSN (FR)

## 🔎 Providers disponibles
- findip
- ipapi
- ipinfo
- Provider HTTP générique par DSN (DsnHttpProvider)

## 🧭 Format DSN
- `<scheme>+<endpoint>`
- Exemples:
  - `findip+https://api.findip.example.com/json/{ip}`
  - `ipapi+https://ip-api.com/json/{ip}`
  - `ipinfo+https://ipinfo.io/{ip}`

### ✅ Contraintes
- L’endpoint doit être http(s) et contenir le placeholder `{ip}`.
- Schemes supportés: `findip`, `ipapi`, `ipinfo` (vous pouvez utiliser des préfixes personnalisés avec un mapper correspondant — voir plus bas).
- `variables.token` requis pour `findip` et `ipinfo` (validation côté configuration).

## ⚙️ Variables et interpolation
- `list.<alias>.variables`: `map<string,string>`
  - `token`: `string` (requis pour findip/ipinfo)
- Interpolation: les placeholders `{ip}`, `{token}`, etc. sont remplacés à l’exécution avant l’appel HTTP.

## 🔌 Provider HTTP générique (DsnHttpProvider)
- Le bundle inclut un provider générique capable d’appeler n’importe quel endpoint HTTP défini par un DSN et de déléguer la transformation à un mapper.
- Signature (constructeur): `(HttpClientInterface $httpClient, string $dsn, array $variables = [], ?object $mapper = null)`
- Contrat de mapping: si `$mapper` possède une méthode `map(array $data, string $ip): GeoApiContextDTO`, son retour est utilisé comme contexte de géolocalisation.
- Gestion d’erreurs: les codes HTTP sont normalisés (par ex. `HTTP_CLIENT_4xx`, `HTTP_SERVER_5xx`) et les erreurs réseau/timeouts exposent des raisons (`timeout`, `transport: ...`).
- Note DSN prefix: un préfixe `<scheme>+` (ex: `ipapi+`) est accepté et retiré avant la requête réelle; il peut être utilisé pour sélectionner un mapper dans votre factory.

## 🔄 Mappers
- Résolution interne via `GeoContextResolver` ou câblage de services:
  - `ipapi`  -> `IpApiMapper`
  - `findip` -> `MaxmindDataMapper` (compatibilité actuelle)
  - `ipinfo` -> `IpInfoMapper`

## 🛡️ Fallback & Circuit breaker
- `provider_fallback_mode: true` pour tenter d’autres providers en cas d’échec du provider sélectionné.
- Un circuit breaker simple évite les appels répétés aux providers en échec (seuil 3, cooldown ~30s).

## 🧪 Exemples de configuration

```yaml
neox_fire_geolocator:
  providers:
    default: ipapi
    list:
      ipapi:
        dsn: "ipapi+https://ip-api.com/json/{ip}"
        variables: { }
      ipinfo:
        dsn: "ipinfo+https://ipinfo.io/{ip}"
        variables:
          token: "%env(IPINFO_TOKEN)%"
      findip:
        dsn: "findip+https://api.findip.example.com/json/{ip}"
        variables:
          token: "%env(FINDIP_TOKEN)%"
```

### Provider personnalisé via DSN générique
- Définissez votre alias et DSN; câblez un mapper pour transformer le payload:
```yaml
# services.yaml (exemple)
App\Geo\MonMapper: ~

Neox\FireGeolocatorBundle\Provider\DsnHttpProvider $monProvider:
  arguments:
    $httpClient: '@http_client'
    $dsn: 'monapi+https://geo.example.com/v1/lookup?ip={ip}&token={token}'
    $variables: { token: '%env(GEO_TOKEN)%' }
    $mapper: '@App\\Geo\\MonMapper'
```

## ⚠️ Limitations
- Informations détaillées sur la tarification/quotas propres à chaque provider: non disponible — à compléter.
- Timeouts/réessais: configurables via Symfony HttpClient (global à l’app); pas d’options dédiées par provider dans le bundle.

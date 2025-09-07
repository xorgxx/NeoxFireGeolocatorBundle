# 📦 Providers & DSN (EN)

## 🔎 Available providers
- findip
- ipapi
- ipinfo
- Generic DSN HTTP provider (DsnHttpProvider)

## 🧭 DSN format
- `<scheme>+<endpoint>`
- Examples:
  - `findip+https://api.findip.example.com/json/{ip}`
  - `ipapi+https://ip-api.com/json/{ip}`
  - `ipinfo+https://ipinfo.io/{ip}`

### ✅ Constraints
- Endpoint must be http(s) and include the `{ip}` placeholder.
- Supported schemes: `findip`, `ipapi`, `ipinfo` (you can still use custom prefixes with a matching mapper, see below).
- `variables.token` is required for `findip` and `ipinfo`.

## ⚙️ Variables and interpolation
- `list.<alias>.variables`: `map<string,string>`
  - `token`: `string` (required for findip/ipinfo)
- Interpolation: placeholders like `{ip}` or `{token}` are replaced at runtime before the HTTP call.

## 🔌 Generic HTTP provider (DsnHttpProvider)
- The bundle includes a generic provider able to call any HTTP endpoint defined by a DSN and to delegate the transformation to a mapper.
- Signature (constructor): `(HttpClientInterface $httpClient, string $dsn, array $variables = [], ?object $mapper = null)`
- Mapping contract: when `$mapper` is provided and has a method `map(array $data, string $ip): GeoApiContextDTO`, its return value is used as the geolocation context.
- Error handling: HTTP status codes are normalized (e.g., `HTTP_CLIENT_4xx`, `HTTP_SERVER_5xx`) and common network/timeouts are surfaced as reasons (`timeout`, `transport: ...`).
- DSN prefix note: a `<scheme>+` prefix (e.g., `ipapi+`) is acceptable and stripped before the actual request; it can be used to select a mapper in your factory.

## 🔄 Built-in mappers
- Internally in `GeoContextResolver` or service wiring:
  - `ipapi`  -> `IpApiMapper`
  - `findip` -> `MaxmindDataMapper` (compatibility mapping)
  - `ipinfo` -> `IpInfoMapper`

## 🛡️ Fallback & Circuit breaker
- `provider_fallback_mode: true` to try other providers if the selected one fails.
- A simple circuit breaker prevents repeated failed calls (threshold 3, cooldown ~30s).

## 🧪 Sample configuration

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

### Custom provider via generic DSN
- Define your alias and DSN; wire a mapper to transform the payload:
```yaml
# services.yaml (example)
App\Geo\MyMapper: ~

Neox\FireGeolocatorBundle\Provider\DsnHttpProvider $myProvider:
  arguments:
    $httpClient: '@http_client'
    $dsn: 'myapi+https://geo.example.com/v1/lookup?ip={ip}&token={token}'
    $variables: { token: '%env(GEO_TOKEN)%' }
    $mapper: '@App\\Geo\\MyMapper'
```

## ⚠️ Limitations
- Provider-specific pricing/quotas: not available — to be completed.
- Timeouts/retries: configure via Symfony HttpClient globally; no per-provider options in this bundle.

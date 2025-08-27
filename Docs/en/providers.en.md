# 📦 Providers & DSN (EN)

## 🔎 Available providers
- findip
- ipapi
- ipinfo

## 🧭 DSN format
- `<scheme>+<endpoint>`
- Examples:
  - `findip+https://api.findip.example.com/json/{ip}`
  - `ipapi+https://ip-api.com/json/{ip}`
  - `ipinfo+https://ipinfo.io/{ip}`

### ✅ Constraints
- Endpoint must be http(s) and include the `{ip}` placeholder.
- Supported schemes: `findip`, `ipapi`, `ipinfo`.
- `variables.token` is required for `findip` and `ipinfo`.

## ⚙️ Variables
- `list.<alias>.variables`: `map<string,string>`
  - `token`: `string` (required for findip/ipinfo)

## 🔄 Mappers
- Internally in `GeoContextResolver`:
  - `ipapi`  -> `IpApiMapper`
  - `findip` -> `MaxmindDataMapper` (current compatibility mapping)
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

## ⚠️ Limitations
- Provider-specific pricing/quotas: not available — to be completed.
- Timeouts/retries: configure via Symfony HttpClient globally; no per-provider options in this bundle.

# 📦 Providers & DSN (FR)

## 🔎 Providers disponibles
- findip
- ipapi
- ipinfo

## 🧭 Format DSN
- `<scheme>+<endpoint>`
- Exemples:
  - `findip+https://api.findip.example.com/json/{ip}`
  - `ipapi+https://ip-api.com/json/{ip}`
  - `ipinfo+https://ipinfo.io/{ip}`

### ✅ Contraintes
- L’endpoint doit être http(s) et contenir le placeholder `{ip}`.
- Schemes supportés: `findip`, `ipapi`, `ipinfo`.
- `variables.token` requis pour `findip` et `ipinfo` (validation côté configuration).

## ⚙️ Variables
- `list.<alias>.variables`: `map<string,string>`
  - `token`: `string` (requis pour findip/ipinfo)

## 🔄 Mappers
- Résolution interne via `GeoContextResolver`:
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

## ⚠️ Limitations
- Informations détaillées sur la tarification/quotas propres à chaque provider: non disponible — à compléter.
- Timeouts/réessais: configurables via Symfony HttpClient (global à l’app); pas d’options dédiées par provider dans le bundle.

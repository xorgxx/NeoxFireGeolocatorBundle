# ⚙️ Configuration (FR)

## Sources de configuration
- Globale via YAML/PHP (packages/neox_fire_geolocator) — toutes les clés ci-dessous.
- Par attribut PHP sur contrôleur/action: #[GeoApi(...)] — fusionné avec la config globale par GeoConfigCollectListener.

## Clés disponibles (arbre principal)
- enabled: bool (default: true)
- event_bridge_service: string|null
- provider_fallback_mode: bool (default: false)
- redirect_on_ban: string|null (URL ou nom de route)
- log_channel: string (default: "neox_fire_geolocator")
- log_level: string (default: "warning")
- simulate: bool (default: false) — peut être surchargé par ?geo_simulate=1/0
- provider: string|null — override global
- force_provider: string|null — force l’alias provider (prioritaire)
- cache_ttl: int|null — TTL pour le cache de contexte (sinon cache.context_ttl)
- block_on_error: bool (default: true) — si provider en erreur et pas de contexte => deny
- exclusion_key: string|null — clé d’exclusion personnalisée

providers
- default: string (ex: findip)
- list: map<alias, { dsn: string, variables?: map<string,string> }>
  - DSN: format "scheme+https://…/{ip}". Schemes supportés: findip, ipinfo, ipapi.
  - variables.token: obligatoire pour findip et ipinfo; optionnel pour ipapi.

cache
- context_ttl: int (default: 300)
- key_strategy: "ip"|"session" (default: ip)
- redis_dsn: string|null — ex: redis://[:password@]host[:port][/db] (validé par l’extension) — si présent, un pool PSR-6 dédié "neox_fire_geolocator.cache_pool" est créé.

exclusions
- key_strategy: "ip"|"session" (default: ip)

storage
- dsn: string|null — ex: redis://… (valide); autres backends possibles via StorageFactory (non détaillés ici).

bans
- max_attempts: int (default: 10)
- ttl: int (default: 3600) — TTL des tentatives avant reset
- ban_duration: string (default: "1 hour") — durée humaine

filters
- navigator: { enabled: bool=true, default_behavior: "allow", rules: string[], priority?: int }
- country:   { default_behavior: "allow", rules: string[], priority?: int }
- ip:        { default_behavior: "allow", rules: string[], priority?: int }
- crawler:   { enabled: bool=true, allow_known: bool=true, default_behavior: "allow", rules: string[], priority?: int }
- vpn:       { enabled: bool=true, default_behavior: "allow"|"block", priority?: int }

trusted
- headers: string[] (default: [X-Forwarded-For, X-Real-IP, …]) — ordre d’extraction.
- proxies: string[] (default: réseaux privés + 127.0.0.1)
- routes:  string[] (exemptions par motif: '_wdt*', '_profiler*', …)

maintenance
- enabled: bool (default: false)
- allowed_roles: string[] (default: [ROLE_ADMIN])
- paths_whitelist: string[] (default: ['/login', '/_profiler', '/_wdt', '/healthz', '/assets'])
- ips_whitelist: string[] (default: [])
- retry_after: int (>=0; default: 600)
- message: string|null
- template: string|null

## Exemples YAML

```yaml
# config/packages/neox_fire_geolocator.yaml
neox_fire_geolocator:
  enabled: true
  simulate: false
  redirect_on_ban: null
  provider_fallback_mode: true
  provider: null
  force_provider: null
  cache_ttl: 600
  block_on_error: true
  exclusion_key: null

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

  cache:
    context_ttl: 300
    key_strategy: ip
    redis_dsn: "%env(resolve:REDIS_URL)%" # optionnel

  exclusions:
    key_strategy: session

  storage:
    dsn: "%env(resolve:REDIS_URL)%" # recommandé en prod

  bans:
    max_attempts: 10
    ttl: 3600
    ban_duration: "1 hour"

  filters:
    navigator:
      enabled: true
      default_behavior: allow
      rules: ['+chrome', '+firefox', '+safari', '+edge', '-android', '-mobile safari']
    country:
      default_behavior: allow
      rules: ['-RU']
    ip:
      default_behavior: allow
      rules: ['+127.0.0.1']
    crawler:
      enabled: true
      allow_known: true
      default_behavior: allow
      rules: []
    vpn:
      enabled: true
      default_behavior: block

  trusted:
    headers: ['X-Forwarded-For','X-Real-IP','CF-Connecting-IP']
    proxies: ['10.0.0.0/8','192.168.0.0/16']
    routes: ['_wdt*','_profiler*']
```

## Bonnes pratiques de prod
- Déclarer les proxies de confiance et ordonner correctement trusted.headers.
- Activer un pool Redis dédié via cache.redis_dsn pour isoler le cache.
- Définir des timeouts de HttpClient (framework/http_client) et limiter les retries.
- Ne pas activer simulate en prod; utilisez les commandes pour diagnostiquer.
- Surveiller block_on_error: en cas d’indisponibilité provider, un deny peut être souhaité (défaut true) ou non selon vos besoins.

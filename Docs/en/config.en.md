# ⚙️ Configuration (EN)

## Configuration sources
- Global via YAML/PHP (packages/neox_fire_geolocator) — all keys below.
- Per-controller/action via PHP attribute #[GeoApi(...)] — merged with global config by GeoConfigCollectListener.

## Top-level keys
- enabled: bool (default: true)
- event_bridge_service: string|null
- provider_fallback_mode: bool (default: false)
- redirect_on_ban: string|null (URL or route name)
- log_channel: string (default: "neox_fire_geolocator")
- log_level: string (default: "warning")
- simulate: bool (default: false) — can be overridden per-request with ?geo_simulate=1/0
- provider: string|null — global override
- force_provider: string|null — forces provider alias (takes precedence)
- cache_ttl: int|null — TTL for context cache (else cache.context_ttl)
- block_on_error: bool (default: true) — if provider fails and no context => deny
- exclusion_key: string|null — custom exclusion key

providers
- default: string (e.g., findip)
- list: map<alias, { dsn: string, variables?: map<string,string> }>
  - DSN format: "scheme+https://…/{ip}". Supported schemes: findip, ipinfo, ipapi.
  - variables.token is required for findip and ipinfo; optional for ipapi.

cache
- context_ttl: int (default: 300)
- key_strategy: "ip"|"session" (default: ip)
- redis_dsn: string|null — e.g., redis://[:password@]host[:port][/db] (validated by the extension). If present, a dedicated PSR-6 pool "neox_fire_geolocator.cache_pool" is created.

exclusions
- key_strategy: "ip"|"session" (default: ip)

storage
- dsn: string|null — e.g., redis://…; other backends via StorageFactory (not detailed here).

bans
- max_attempts: int (default: 10)
- ttl: int (default: 3600)
- ban_duration: string (default: "1 hour")

filters
- navigator: { enabled: bool=true, default_behavior: "allow", rules: string[], priority?: int }
- country:   { default_behavior: "allow", rules: string[], priority?: int }
- ip:        { default_behavior: "allow", rules: string[], priority?: int }
- crawler:   { enabled: bool=true, allow_known: bool=true, default_behavior: "allow", rules: string[], priority?: int }
- vpn:       { enabled: bool=true, default_behavior: "allow"|"block", priority?: int }

trusted
- headers: string[] (default includes X-Forwarded-For, X-Real-IP, …) — extraction order.
- proxies: string[] (defaults to private ranges and 127.0.0.1)
- routes:  string[] (exempt routes patterns: '_wdt*', '_profiler*', …)

maintenance
- enabled: bool (default: false)
- allowed_roles: string[] (default: [ROLE_ADMIN])
- paths_whitelist: string[] (default: ['/login', '/_profiler', '/_wdt', '/healthz', '/assets'])
- ips_whitelist: string[] (default: [])
- retry_after: int (>=0; default: 600)
- message: string|null
- template: string|null

## YAML examples

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
    redis_dsn: "%env(resolve:REDIS_URL)%" # optional

  exclusions:
    key_strategy: session

  storage:
    dsn: "%env(resolve:REDIS_URL)%" # recommended in prod

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

## Production best practices
- Declare trusted proxies and headers in the right order.
- Use a dedicated Redis pool via cache.redis_dsn to isolate cache.
- Configure HttpClient timeouts (framework/http_client) and limit retries.
- Do not enable simulate in production; use CLI commands to diagnose.
- Consider block_on_error carefully: when the provider is down, deny-by-default (true) may be desired.

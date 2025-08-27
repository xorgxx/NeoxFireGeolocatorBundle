# 🍳 Usage / Recipes (EN)

## 🌐 Deployment behind CDN/Proxy
- Declare trusted proxies (`framework.trusted_proxies`) and set `trusted.headers` to extract the correct client IP.
- Example:

```yaml
# config/packages/framework.yaml (excerpt)
framework:
  trusted_proxies: ['10.0.0.0/8', '192.168.0.0/16']
  trusted_headers: ['x-forwarded-for','x-forwarded-proto','x-forwarded-host']

neox_fire_geolocator:
  trusted:
    headers: ['X-Forwarded-For','X-Real-IP','CF-Connecting-IP']
    proxies: ['10.0.0.0/8','192.168.0.0/16']
```

## 🚦 Rate limiting, exclusions, bans
- Rate limiting: the bundle uses `RateLimiterGuard` if a limiter `limiter.neox_fire_geolocator` is defined in the host app.
- Exclusions: set a key (e.g., per-session) and add it via `ExclusionManager` (exact API depends on your integration).
- Bans: managed through `StorageInterface` (Redis recommended). Use the `neox:firegeolocator:ban` CLI.

## 🖼️ Templates HTML/JSON
- `ResponseFactory` automatically picks `problem+json` when requested, otherwise JSON or HTML.
- Header `X-Geolocator-Simulate: 1|0` indicates simulation mode.
- Default Twig templates referenced as `@Geolocator/deny.html.twig` and `@Geolocator/banned.html.twig`. You can provide your own in the host app.

## ↪️ Redirects
- `redirect_on_ban`: route name or absolute/relative URL. When set, a `302` `RedirectResponse` is returned on deny/banned.

## 🐞 Debugging and simulate mode
- Enable `simulate` globally (not in production) or per-controller attribute. Override per-request with `?geo_simulate=1`.
- Profiler: Geolocator panel shows context, cache (hit/miss/save), and decisions.

## 💾 Storage
- PSR-6 cache: defaults to `cache.app` alias. Dedicated Redis pool created when `cache.redis_dsn` is set (id: `neox_fire_geolocator.cache_pool`).
- Bans/attempts: backend created by `StorageFactory` (Redis highly recommended).

## 🔁 Migration & backward compatibility
- Not available — to be completed.

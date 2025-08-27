# 📚 FireGeolocatorBundle — Documentation (EN)

## 📑 Contents
- [Architecture and flow](#architecture-and-flow)
- [Configuration](./config.en.md)
- [Providers & DSN](./providers.en.md)
- [Built-in filters](./filters.en.md)
- [Create a custom filter](./custom-filter.en.md)
- [CLI Commands](./commands.en.md)
- [PHP Attributes](./attributes.en.md)
- [Usage / Recipes](./usage.en.md)
- [Responses & formats](./responses.en.md)
- FAQ / Migration notes: *not available — to be completed*

---

## ⚙️ Architecture and flow

### 🔔 Event listeners
- **GeolocatorRequestListener** (`KernelEvents::REQUEST`, priority 8) → main orchestrator.  
- **TrustedProxyRequestListener** → applies *trusted* config (headers/proxies/routes) early.  
- **MaintenanceRequestListener** → applies maintenance mode (roles/paths/IPs whitelists).  
- **GeoConfigCollectListener** → merges global config and `#[GeoApi]` attribute to expose a `ResolvedGeoApiConfigDTO` on the request.

### 🔑 Core services
- **GeoContextResolver** → resolves geo context via configured providers; PSR-6 cache; provider fallback; circuit breaker.  
- **ResponseFactory** → builds responses (**HTML / JSON / problem+json**); handles `redirect_on_ban`; adds header `X-Geolocator-Simulate`.  
- **BanManager & StorageInterface** → attempts counter, bans with TTL, stats; storage backends via `StorageFactory`.  
- **ExclusionManager** → temporary exclusions by key (IP/session).  
- **RateLimiterGuard** → optional integration with Symfony rate limiter.  
- **FilterChain** → runs filters tagged `neox_fire_geolocator.filter` in priority order.

### 🧩 Built-in filters (priorities from `services.yaml`)
- **IpFilter** (400)  
- **VpnFilter** (300)  
- **NavigatorFilter** (200)  
- **CountryFilter** (150)  
- **CrawlerFilter** (100)

### 🛠️ Profiler & Templates
- Profiler: `NeoxFireGeolocatorDataCollector` (template `@NeoxFireGeolocator/Collector/geolocator.html.twig`).  
- Templates: Twig namespace **"NeoxFireGeolocator"** registered; default responses via `@Geolocator/...` (customizable in the host app).

---

## 🔄 Rule evaluation order

1. Route exemptions (`trusted.routes`)  
2. Session/IP context cache  
3. Exclusions (`ExclusionManager`)  
4. Rate limiting (`RateLimiterGuard`)  
5. Active ban (`BanManager`)  
6. Provider error (`block_on_error`)  
7. FilterChain (**first deny wins**, *explicit allow remembered*)  

---

## 🔗 Links
- [Configuration](./config.en.md)  
- [Providers & DSN](./providers.en.md)  
- [Filters](./filters.en.md)  
- [Custom filter](./custom-filter.en.md)  
- [CLI Commands](./commands.en.md)  
- [Attributes](./attributes.en.md)  
- [Usage](./usage.en.md)  
- [Responses & formats](./responses.en.md)  

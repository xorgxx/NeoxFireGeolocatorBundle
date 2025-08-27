# 🧱 Built-in filters (EN)

## 🧩 Filters and parameters
- ip
  - `default_behavior`: `allow|block` (default: `allow`)
  - `rules`: `string[]` — signed rules "+/-"; exact IPv4/IPv6 or CIDR (e.g., `'+127.0.0.1'`, `'-10.0.0.0/8'`)
  - Priority: 400
  - Logic: whitelist first (+) -> allow; blacklist (-) -> deny; otherwise `default_behavior`.
- vpn
  - `enabled`: `bool` (default: `true`)
  - `default_behavior`: `allow|block` (default: `allow`)
  - Decision: if `proxy/hosting` true in context, deny when `default_behavior != allow`.
  - Priority: 300
- navigator (browser User-Agent)
  - `enabled`: `bool` (default: `true`)
  - `default_behavior`: `allow|block` (default: `allow`)
  - `rules`: `string[]` — patterns (+/-). Simple tokens, substrings or regex `/.../i`. Defaults provided.
  - Priority: 200
- country
  - `default_behavior`: `allow|block` (default: `allow`)
  - `rules`: `['+FR','-RU', …]` (2-letter country codes)
  - Priority: 150
- crawler (robot User-Agent)
  - `enabled`: `bool` (default: `true`)
  - `allow_known`: `bool` (default: `true`)
  - `default_behavior`: `allow|block` (default: `allow`)
  - `rules`: `string[]` — patterns (+/-). Special `'known'` pattern supported.
  - Priority: 100

## ✍️ Signed rules
- Array of strings starting with `+` (allow) or `-` (deny).
- Examples:
  - `country.rules`: `['+FR', '+BE', '-RU']`
  - `ip.rules`:      `['+127.0.0.1', '-10.0.0.0/8']`
  - `navigator.rules`: `['+chrome', '-/.*android.*/i']`
  - `crawler.rules`: `['-curl', '+known']`

## ⏱️ Execution order
- Filters run by priority. The first deny (`allowed=false`) stops the chain; an explicit allow may be remembered and returned if no deny occurs. If no filter decides, access is allowed (`null` -> implicit allow).

## 🧪 Simulate mode
- `simulate: true` (global/attribute) or via `?geo_simulate=1`
- In simulate mode, denies and bans do not block — the decision is logged and the `X-Geolocator-Simulate` header is set.

## 🧷 Configuration examples

```yaml
neox_fire_geolocator:
  filters:
    ip:
      default_behavior: block
      rules: ['+127.0.0.1', '+10.0.0.0/8']
    country:
      default_behavior: allow
      rules: ['-RU', '-KP']
    navigator:
      enabled: true
      default_behavior: allow
      rules: ['+chrome','+firefox','-android']
    crawler:
      enabled: true
      allow_known: true
      default_behavior: allow
      rules: ['-curl']
    vpn:
      enabled: true
      default_behavior: block
```

## 🔗 Interactions
- Exclusions (`ExclusionManager`): when the request is excluded, filters are bypassed.
- Bans: a deny increments attempts and can lead to a ban (see `bans.ttl` / `ban_duration`).
- Rate limiting: when exceeded, immediate deny (429/403) before filters evaluation.

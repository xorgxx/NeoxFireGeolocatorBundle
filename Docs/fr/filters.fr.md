# 🧱 Filtres intégrés (FR)

## 🧩 Filtres et paramètres
- ip
  - `default_behavior`: `allow|block` (default: `allow`)
  - `rules`: `string[]` — règles signées "+/-"; IPv4/IPv6 exactes ou CIDR (ex: `'+127.0.0.1'`, `'-10.0.0.0/8'`)
  - Priorité: 400
  - Logique: whitelist prioritaire (+) -> autorise; blacklist (-) -> refuse; sinon `default_behavior`.
- vpn
  - `enabled`: `bool` (default: `true`)
  - `default_behavior`: `allow|block` (default: `allow`)
  - Décision: si `proxy/hosting` true dans le contexte alors bloque si `default_behavior != allow`.
  - Priorité: 300
- navigator (User-Agent navigateur)
  - `enabled`: `bool` (default: `true`)
  - `default_behavior`: `allow|block` (default: `allow`)
  - `rules`: `string[]` — motifs (+/-). Motifs simples, substring ou regex `/.../i`. Exemples fournis par défaut.
  - Priorité: 200
- country
  - `default_behavior`: `allow|block` (default: `allow`)
  - `rules`: `['+FR','-RU', …]` (codes pays 2 lettres)
  - Priorité: 150
- crawler (User-Agent robot)
  - `enabled`: `bool` (default: `true`)
  - `allow_known`: `bool` (default: `true`)
  - `default_behavior`: `allow|block` (default: `allow`)
  - `rules`: `string[]` — motifs (+/-). Motif spécial `'known'` pris en charge.
  - Priorité: 100

## ✍️ Règles signées
- Format: tableau de chaînes commençant par `+` (allow) ou `-` (deny)
- Exemples:
  - `country.rules`: `['+FR', '+BE', '-RU']`
  - `ip.rules`:      `['+127.0.0.1', '-10.0.0.0/8']`
  - `navigator.rules`: `['+chrome', '-/.*android.*/i']`
  - `crawler.rules`: `['-curl', '+known']`

## ⏱️ Ordre d’exécution
- Les filtres sont exécutés selon leurs priorités. Le premier refus (`allowed=false`) arrête la chaîne; un allow explicite peut être mémorisé et renvoyé si aucun refus n’intervient ensuite. Si aucun filtre ne décide, l’accès est autorisé (`null` -> allow implicite).

## 🧪 Simulate mode
- `simulate: true` (global/attribut) ou via `?geo_simulate=1`
- En simulate, les refus et bans ne bloquent pas — la décision est loggée et l’en-tête `X-Geolocator-Simulate` est positionné.

## 🧷 Exemples de configuration

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
- Exclusions (`ExclusionManager`): si la requête est exclue, les filtres ne s’appliquent pas.
- Bans: un refus incrémente les tentatives et peut conduire à un ban (voir `bans.ttl` / `ban_duration`).
- Rate limiting: si dépassé, deny immédiat (429/403 selon contexte) avant l’évaluation des filtres.

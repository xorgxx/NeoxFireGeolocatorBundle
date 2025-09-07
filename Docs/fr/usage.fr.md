# 🍳 Usage / Recettes (FR)

## 🌐 Déploiement derrière CDN/Proxy
- Déclarez les proxies de confiance (`framework.trusted_proxies`) et alimentez `trusted.headers` pour extraire la bonne IP.
- Exemple:

```yaml
# config/packages/framework.yaml (extrait)
framework:
  trusted_proxies: ['10.0.0.0/8', '192.168.0.0/16']
  trusted_headers: ['x-forwarded-for','x-forwarded-proto','x-forwarded-host']

neox_fire_geolocator:
  trusted:
    headers: ['X-Forwarded-For','X-Real-IP','CF-Connecting-IP']
    proxies: ['10.0.0.0/8','192.168.0.0/16']
```

## 🚦 Rate limiting, exclusions, bans
- Rate limiting: le bundle utilise `RateLimiterGuard` si un limiteur `limiter.neox_fire_geolocator` est défini côté app.
- Exclusions: définissez une clé (ex: per‑session) et ajoutez-la via `ExclusionManager` (API exacte selon votre intégration applicative).
- Bans: gérés via `StorageInterface` (Redis recommandé). Utilisez la CLI `neox:firegeolocator:ban` pour manipuler.

## 🖼️ Templates HTML/JSON
- `ResponseFactory` choisit automatiquement `problem+json` si demandé, sinon JSON ou HTML en dernier recours.
- L’entête `X-Geolocator-Simulate: 1|0` indique si une décision serait bloquante en mode simulate.
- Templates Twig par défaut référencés comme `@Geolocator/deny.html.twig` et `@Geolocator/banned.html.twig`. Vous pouvez fournir vos propres templates dans l’application hôte.

## ↪️ Redirections
- `redirect_on_ban`: route ou URL absolue/relative. Si configuré, un `RedirectResponse` 302 est renvoyé sur deny/banned.

## 🐞 Débogage et simulate mode
- Activer `simulate` globalement (ne pas faire en prod) ou par attribut. Surcharger par requête: `?geo_simulate=1`.
- Profiler: onglet Geolocator (DataCollector) affiche contexte, cache (hit/miss/save), décisions.

## 💾 Stockages
- Cache PSR-6: par défaut alias sur `cache.app`. Redis dédié si `cache.redis_dsn` est défini (pool: `neox_fire_geolocator.cache_pool`).
- Bans/attempts: `StorageFactory` crée le backend adéquat (Redis fortement recommandé).

## 🔁 Stratégies de migration et rétrocompatibilité
- Passage depuis une config ancienne (sans DSN générique):
  - Conservez vos alias existants et remplacez les URLs par des DSN `<scheme>+<endpoint>` avec placeholders.
  - Exemple: `https://ip-api.com/json/{ip}` devient `ipapi+https://ip-api.com/json/{ip}`.
- Introduction du DsnHttpProvider:
  - Si vous aviez un provider custom, vous pouvez le remplacer par `DsnHttpProvider` + un mapper léger (`map(array $data, string $ip): GeoApiContextDTO`).
- En-têtes de confiance (trusted headers):
  - Vérifiez que `framework.trusted_proxies` et `trusted.headers` sont alignés entre Symfony et le bundle (`neox_fire_geolocator.trusted.headers`).
- Simulate et négociation de format:
  - Le paramètre `?geo_simulate=1` est privilégié pour des tests sans blocage; pour des intégrations API, annoncez `Accept: application/problem+json`.
- Stockage et cache:
  - Activez Redis via `cache.redis_dsn` et `storage.dsn` pour de meilleures perfs; renommez d’anciens ids de pools si nécessaire vers `neox_fire_geolocator.cache_pool`.
- Filtres et priorités:
  - Les priorités par défaut: ip(400) > vpn(300) > navigator(200) > country(150) > crawler(100). Adaptez vos surcharges de services si vous changiez l’ordre auparavant.
- Rate limiting:
  - Si vous utilisiez un limitateur personnalisé, migrez vers un `limiter.neox_fire_geolocator` déclaré côté app pour activer `RateLimiterGuard` automatiquement.
- Redirects ban/deny:
  - Si vous utilisiez des redirections côté contrôleur, préférez la configuration `redirect_on_ban` désormais gérée par `ResponseFactory`.

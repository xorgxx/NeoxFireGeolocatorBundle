# 📨 Réponses & formats (FR)

## 🏭 ResponseFactory
- Négociation de format:
  - `application/problem+json` si explicitement demandé (Accept ou X-Requested-With…)
  - `application/json` sinon si souhaité
  - HTML (Twig) par défaut
- En-têtes
  - `X-Geolocator-Simulate`: `"1"` si simulate actif sur la requête, `"0"` sinon
- Redirections
  - `redirect_on_ban`: si configuré, renvoie un 302 vers route/URL pour denied/banned

## 🧩 Templates
- Par défaut, les vues sont rendues avec:
  - `@Geolocator/deny.html.twig` (403)
  - `@Geolocator/banned.html.twig` (429)
- Le namespace Twig du bundle "NeoxFireGeolocator" est préconfiguré pour les templates internes (ex: collector du profiler).
- Vous pouvez surcharger/fournir vos propres templates dans l’application.

## 📦 Payloads (exemples)
- problem+json deny (403):
```json
{
  "type": "about:blank",
  "title": "Access denied",
  "status": 403,
  "detail": "Denied by ip:10.0.0.0/8",
  "instance": "/secure",
  "blockingFilter": "ip:10.0.0.0/8",
  "context": {"ip":"1.2.3.4","country":"France","countryCode":"FR"}
}
```

- problem+json banned (429):
```json
{
  "type": "about:blank",
  "title": "Too Many Requests",
  "status": 429,
  "detail": "You have been temporarily blocked due to too many attempts.",
  "instance": "/secure",
  "retry_at": "2025-08-27T12:00:00Z",
  "context": {"ip":"1.2.3.4","country":"France","countryCode":"FR"}
}
```

## 🌍 Internationalisation (i18n)
- Les messages problem+json utilisent un `TranslatorInterface` si disponible (domaine: `geolocator`). En absence de traductions, des fallbacks en anglais sont utilisés.

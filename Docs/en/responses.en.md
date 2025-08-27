# 📨 Responses & formats (EN)

## 🏭 ResponseFactory
- Format negotiation:
  - `application/problem+json` when explicitly requested
  - `application/json` otherwise if requested
  - HTML (Twig) by default
- Headers
  - `X-Geolocator-Simulate`: "1" when simulate active, "0" otherwise
- Redirects
  - `redirect_on_ban`: when configured, a 302 redirect is issued for denied/banned

## 🧩 Templates
- Defaults:
  - `@Geolocator/deny.html.twig` (403)
  - `@Geolocator/banned.html.twig` (429)
- Twig namespace "NeoxFireGeolocator" is pre-registered for internal templates (e.g., profiler collector).
- You may override/provide your own templates in the host application.

## 📦 Payload examples
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

## 🌍 Internationalization (i18n)
- Messages use a `TranslatorInterface` if available (domain: `geolocator`). English fallbacks are used otherwise.

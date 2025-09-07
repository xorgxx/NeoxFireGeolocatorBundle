# Confidentialité et Anonymisation

Ce bundle applique une approche « privacy-by-design » pour toutes les fonctionnalités liées aux IP. Aucune IP en clair n’est stockée dans le cache, la session ou les logs.

Points clés:
- Les identifiants d’IP sont dérivés via HMAC-SHA256(ip_normalisée, secret) et tronqués à 32 hex (ip_hash).
- Les clés sont standardisées: rate_limit:v1:<hmac32>, ban:v1:<hmac32>, exclusion:v1:<hmac32>, geo_ctx:session:<provider>:<sid> ou geo_ctx:ip:v1:<provider>:<hmac32>.
- Les contextes stockés en session/cache sont « sanitizés » pour supprimer les PII (ip, city, latitude, longitude, userAgent…).
- Une migration « dual-read » peut être activée pour lire les anciennes clés et réécrire aux nouveaux formats.

Configuration (parameters dans services.yaml):
- geolocator_privacy.hash_secret (obligatoire via ENV GEO_HASH_SECRET)
- geolocator_privacy.hash_algo_version (par défaut v1)
- geolocator_privacy.geo_cache_key_strategy (ip|session)
- geolocator_privacy.context_sanitizer.fields_to_remove
- geolocator_privacy.hash_truncate (par défaut 32)
- geolocator_privacy.enable_dual_read (par défaut true)

Rate limit par défaut (fallback):
- Si le composant Symfony RateLimiter n’est pas configuré, un limiteur de secours « fixed window » est appliqué via StorageInterface.
- Paramètres (services.yaml):
  - neox_fire_geolocator.rate_limiter.fallback_limit (par défaut 60)
  - neox_fire_geolocator.rate_limiter.fallback_window_ttl (par défaut 60 secondes)

CLI:
- geolocator:hash-ip <ip> affiche algo_version et ip_hash.
- neox:firegeolocator:ban … gère bans/tentatives avec des clés anonymisées (voir Dev-info/docs/commands.md).

# Privacy & Anonymization

This bundle implements privacy-by-design for IP-related features. No raw IP is stored in cache, session or logs.

Key points:
- IP identifiers are derived via HMAC-SHA256(ip_normalized, secret) and truncated to 32 hex (ip_hash).
- Keys use standardized namespaces: rate_limit:v1:<hmac32>, ban:v1:<hmac32>, exclusion:v1:<hmac32>, geo_ctx:session:<provider>:<sid> or geo_ctx:ip:v1:<provider>:<hmac32>.
- Contexts stored in session/cache are sanitized to remove PII (ip, city, latitude, longitude, userAgent...).
- Dual-read migration can be enabled to read legacy keys and rewrite to new ones.

Configuration (services.yaml parameters):
- geolocator_privacy.hash_secret (required via ENV GEO_HASH_SECRET)
- geolocator_privacy.hash_algo_version (default v1)
- geolocator_privacy.geo_cache_key_strategy (ip|session)
- geolocator_privacy.context_sanitizer.fields_to_remove
- geolocator_privacy.hash_truncate (default 32)
- geolocator_privacy.enable_dual_read (default true)

CLI
- geolocator:hash-ip <ip> prints algo_version and ip_hash.
- geolocator:migrate-keys (planned) will migrate legacy keys via the StorageFactory.

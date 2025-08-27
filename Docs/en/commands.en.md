# 🧰 CLI Commands (EN)

## 🔬 Test provider
- Name: `neox:firegeolocator:test-provider`
- Description: Tests a configured geolocation provider.
- Arguments: `[provider?] [ip?]`
- Options: `--list`, `--compact`, `--normalized`, `--simulate`, `--validate`, `--all`, `--health`
- Examples:
```bash
php bin/console neox:firegeolocator:test-provider --list
php bin/console neox:firegeolocator:test-provider ipapi 1.2.3.4 --compact
php bin/console neox:firegeolocator:test-provider ipinfo --simulate --all
php bin/console neox:firegeolocator:test-provider --health
```

## 🚫 Ban manager
- Name: `neox:firegeolocator:ban` (alias: `neox:firegeolocator:ban:add`)
- Description: Unified ban management.
- Usage: `neox:firegeolocator:ban <action> [subject] [options]`
- Actions: `add | unban | status | attempts | list | stats | clear-expired`
- Options:
  - `--bucket` (treat subject as full bucket without `ip-` prefix)
  - `--reason=<text>` (add)
  - `--ttl=<seconds>` (add, attempts)
  - `--duration="1 hour"` (add)
  - `--incr=<n>` (attempts)
  - `--reset` (attempts)
- Examples:
```bash
php bin/console neox:firegeolocator:ban add 82.67.99.78 --reason abuse --duration "1 hour"
php bin/console neox:firegeolocator:ban status 1.2.3.4
php bin/console neox:firegeolocator:ban attempts 1.2.3.4 --incr 3
php bin/console neox:firegeolocator:ban list
```

## 🛠️ Maintenance
- Name: `neox:firegeolocator:maintenance`
- Description: Enables/disables maintenance mode (TTL and comment supported)
- Usage: `neox:firegeolocator:maintenance <enable|disable|status> [options]`
- Options:
  - `--ttl=<seconds>`
  - `-d, --duration="15 minutes"`
  - `-m, --comment="reason"`
- Examples:
```bash
php bin/console neox:firegeolocator:maintenance enable -d "1 hour" -m "Upgrade DB"
php bin/console neox:firegeolocator:maintenance status
php bin/console neox:firegeolocator:maintenance disable
```

## 🧾 Exit codes
- `0`: success; `2`: validation/usage error (depending on action). Other non-zero codes may be used on provider failures.

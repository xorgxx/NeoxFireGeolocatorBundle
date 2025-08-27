# 🧰 Commandes CLI (FR)

## 🔬 Test provider
- Nom: `neox:firegeolocator:test-provider`
- Description: Teste un provider de géolocalisation configuré.
- Arguments: `[provider?] [ip?]`
- Options: `--list`, `--compact`, `--normalized`, `--simulate`, `--validate`, `--all`, `--health`
- Exemples:
```bash
php bin/console neox:firegeolocator:test-provider --list
php bin/console neox:firegeolocator:test-provider ipapi 1.2.3.4 --compact
php bin/console neox:firegeolocator:test-provider ipinfo --simulate --all
php bin/console neox:firegeolocator:test-provider --health
```

## 🚫 Ban manager
- Nom: `neox:firegeolocator:ban` (alias: `neox:firegeolocator:ban:add`)
- Description: Gestion unifiée des bannissements.
- Usage: `neox:firegeolocator:ban <action> [subject] [options]`
- Actions: `add | unban | status | attempts | list | stats | clear-expired`
- Options:
  - `--bucket` (subject est un bucket complet sans préfixe `ip-`)
  - `--reason=<text>` (add)
  - `--ttl=<seconds>` (add, attempts)
  - `--duration="1 hour"` (add)
  - `--incr=<n>` (attempts)
  - `--reset` (attempts)
- Exemples:
```bash
php bin/console neox:firegeolocator:ban add 82.67.99.78 --reason abuse --duration "1 hour"
php bin/console neox:firegeolocator:ban status 1.2.3.4
php bin/console neox:firegeolocator:ban attempts 1.2.3.4 --incr 3
php bin/console neox:firegeolocator:ban list
```

## 🛠️ Maintenance
- Nom: `neox:firegeolocator:maintenance`
- Description: Active/Désactive le mode maintenance (TTL et commentaire pris en charge)
- Usage: `neox:firegeolocator:maintenance <enable|disable|status> [options]`
- Options:
  - `--ttl=<seconds>`
  - `-d, --duration="15 minutes"`
  - `-m, --comment="raison"`
- Exemples:
```bash
php bin/console neox:firegeolocator:maintenance enable -d "1 hour" -m "Upgrade DB"
php bin/console neox:firegeolocator:maintenance status
php bin/console neox:firegeolocator:maintenance disable
```

## 🧾 Codes de sortie
- `0`: succès; `2`: erreur de validation/usage (selon action). D’autres codes peuvent être utilisés en cas d’échec provider.

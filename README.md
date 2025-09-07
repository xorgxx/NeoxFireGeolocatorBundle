# 🔥 FireGeolocatorBundle

**FR (français en premier) — EN (English below)**

---

## 🇫🇷 FR

### 📌 Résumé
- **FireGeolocatorBundle** fournit des contrôles d’accès basés sur :
  - la **géolocalisation**  
  - l’**adresse IP**  
  - l’**agent utilisateur** (navigateur/crawler)  
  - la **détection VPN/Proxy**  

- Il s’intègre au cycle de vie des requêtes HTTP, applique une **chaîne de filtres configurable**, gère **exclusions et bans temporaires**, et renvoie des réponses adaptées (**HTML, JSON, problem+json**) avec un mode **simulation**.

- **Cas d’usage** :  
  Restreindre l’accès par pays/IP, bloquer les crawlers, refuser les VPN/Proxies connus, appliquer du rate limiting, bannir temporairement des clients abusifs… tout en conservant des exclusions (whitelists) et des outils CLI de diagnostic.

---

### ⚙️ Installation

1. **Composer (dans votre application Symfony)**  
   ```bash
   composer require neox/fire-geolocator-bundle
   ```
   *(⚠️ nom de package réel : à compléter)*

2. **Activation du bundle**  
   Via Symfony Flex usuel. Sinon, ajouter manuellement dans `config/bundles.php` :  
   ```php
   return [
       Neox\FireGeolocatorBundle\NeoxFireGeolocatorBundle::class => ['all' => true],
   ];
   ```

3. **Versions supportées**  
   - PHP : `>= 8.2` (attributs PHP 8, types stricts)  
   - Symfony : `6 / 7` (7.x confirmé, usage de `#[AsEventListener]` et AssetMapper)

---

### ⚡ Configuration rapide

`config/packages/neox_fire_geolocator.yaml` :

```yaml
neox_fire_geolocator:
  enabled: true
  simulate: false
  provider_fallback_mode: false

  providers:
    default: findip
    list:
      findip:
        dsn: "findip+https://api.findip.example.com/ip/{ip}"
        variables:
          token: "%env(FINDIP_TOKEN)%"
      ipapi:
        dsn: "ipapi+https://ip-api.com/json/{ip}"
        variables: { }
      ipinfo:
        dsn: "ipinfo+https://ipinfo.io/{ip}"
        variables:
          token: "%env(IPINFO_TOKEN)%"

  cache:
    context_ttl: 300
    key_strategy: ip   # ip|session

  filters:
    navigator:
      enabled: true
      default_behavior: allow
      rules: ['+chrome', '+firefox', '+safari', '+edge', '-android', '-mobile safari']
    crawler:
      enabled: true
      allow_known: true
      default_behavior: allow
      rules: []
    country:
      default_behavior: allow
      rules: ['-RU', '-KP']
    ip:
      default_behavior: allow
      rules: ['+127.0.0.1', '-10.0.0.0/8']
    vpn:
      enabled: true
      default_behavior: block
```

---

### 🚀 Fonctionnalités clés
- Attribut PHP `#[GeoApi]` pour configurer par **contrôleur/action** (override de la config globale).
- Résolution de contexte via **providers** : `findip`, `ipapi`, `ipinfo` (DSN `scheme+https://.../{ip}`).
- Filtres intégrés : `ip`, `country`, `navigator (UA)`, `crawler`, `vpn`.
- **Exclusions temporaires** + gestion de **bans** (tentatives, TTL, durée humaine).
- **Rate limiting** complémentaire via `RateLimiterGuard`.
- Réponses **HTML / JSON / problem+json** + header `X-Geolocator-Simulate`.
- Profiler Symfony + DataCollector (barre WDT).
- Mode maintenance CLI + whitelists.

---

### 🧑‍💻 Exemple d’usage

```php
use Neox\FireGeolocatorBundle\Attribute\GeoApi;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

#[Route('/secure')]
#[GeoApi(
    enabled: true,
    simulate: false,
    countries: ['+FR', '+BE', '-RU'],
    ips: ['+127.0.0.1'],
    vpnEnabled: true,
    vpnDefaultBehavior: 'block',
)]
final class SecureController extends AbstractController
{
    #[Route('', name: 'secure_home')]
    public function __invoke(): Response
    {
        return new Response('OK');
    }
}
```

🔎 **Décision d’autorisation/refus** :  
La chaîne de filtres évalue successivement (priorité : **IP → VPN → Navigateur → Country → Crawler**).  
- Premier refus = stop  
- `allow` explicite peut court-circuiter  
- Sinon → comportement par défaut  

---

### 📚 Documentation détaillée
- [Docs/fr](./Docs/fr/INDEX.fr.md)  
- [Docs/en](./Docs/en/INDEX.en.md)  

---

### 🛠️ Démonstrations & Exemples

#### CLI
```bash
php bin/console neox:firegeolocator:test-provider --list
php bin/console neox:firegeolocator:test-provider findip 1.2.3.4 --compact
php bin/console neox:firegeolocator:ban status 1.2.3.4
php bin/console neox:firegeolocator:maintenance status
```

#### HTTP
- Tester en simulation :  
  `https://yourapp.test/secure?geo_simulate=1`

---

### ✅ Bonnes pratiques
- Configurez **trusted proxies/headers** correctement pour récupérer l’IP client.
- Ajoutez des **timeouts** via `HttpClient` global.
- Préférez **Redis** pour le cache/bans en production.
- N’activez **jamais** `simulate` en prod.
- Vérifiez la cohérence entre `trusted.routes` et vos firewalls internes.

---

### 🤝 Support & Contribution
- Ouvrir une **issue** ou une **PR** sur le dépôt.  
- Documenter votre environnement et fournir un extrait anonymisé de config.  

---

---

## 🇬🇧 EN

### 📌 Summary
- **FireGeolocatorBundle** provides **access control** based on:
  - geolocation  
  - IP address  
  - user agent (navigator/crawler)  
  - VPN/Proxy detection  

- It plugs into the HTTP request cycle, applies a **configurable filter chain**, manages **exclusions and bans**, and returns responses (**HTML / JSON / problem+json**) with a **simulation mode**.

---

### ⚙️ Installation
1. **Composer**
   ```bash
   composer require neox/fire-geolocator-bundle
   ```
   *(actual package name TBD)*

2. **Bundle enablement**  
   Usually via Symfony Flex, otherwise add in `config/bundles.php`.

3. **Supported versions**
   - PHP: `>= 8.2`  
   - Symfony: `6 / 7`

---

### ⚡ Quick configuration
See the **French YAML block above** – keys are identical.

---

### 🚀 Key features
- Per-controller attribute `#[GeoApi]`.  
- Providers: `findip`, `ipapi`, `ipinfo` (DSN with `{ip}`).  
- Built-in filters: **ip, country, navigator, crawler, vpn**.  
- Temporary exclusions & bans with TTL.  
- Rate limiting integration.  
- Response negotiation + header `X-Geolocator-Simulate`.  
- Symfony profiler + maintenance mode CLI.

---

### 🧑‍💻 Quick usage
- Add `#[GeoApi(...)]` to your controller/action.  
- **FilterChain logic**: first deny wins, explicit allow may short-circuit, otherwise default behavior applies.

---

### 📚 Documentation
- [Docs/fr](./Docs/fr/INDEX.fr.md)  
- [Docs/en](./Docs/en/INDEX.en.md)  

---

### 🛠️ Examples
- CLI: `test-provider`, `ban manager`, `maintenance`  
- HTTP: add `?geo_simulate=1`

---

### ✅ Best practices
- Configure **trusted proxies/headers** and **Redis caching** properly.  
- Avoid enabling `simulate` in production.  

---

### 🤝 Support & Contribution
- Use **issues / PRs** with anonymized configuration snippets.  

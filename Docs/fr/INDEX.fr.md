# 📚 FireGeolocatorBundle — Documentation (FR)

## 📑 Sommaire
- [Architecture et flux](#architecture-et-flux)
- [Configuration](./config.fr.md)
- [Providers & DSN](./providers.fr.md)
- [Filtres intégrés](./filters.fr.md)
- [Créer un filtre personnalisé](./custom-filter.fr.md)
- [Commandes CLI](./commands.fr.md)
- [Attributs PHP](./attributes.fr.md)
- [Usage avancé / Recettes](./usage.fr.md)
- [Réponses & formats](./responses.fr.md)
- FAQ / Notes de migration: *non disponible — à compléter*

---

## ⚙️ Architecture et flux

### 🔔 Écouteurs d’évènements (listeners)
- **GeolocatorRequestListener** (`KernelEvents::REQUEST`, priorité 8) → orchestrateur principal.  
- **TrustedProxyRequestListener** → applique la configuration *trusted* (headers/proxies/routes) en amont.  
- **MaintenanceRequestListener** → applique le mode maintenance (*whitelist rôles/paths/IP*) si activé.  
- **GeoConfigCollectListener** → fusionne la configuration globale et l’attribut `#[GeoApi]` pour exposer un `ResolvedGeoApiConfigDTO` dans l’attribut de requête.

### 🔑 Services clés
- **GeoContextResolver** → résout le contexte géo (IP, pays, etc.) via providers ; cache PSR-6 ; fallback providers ; circuit breaker.  
- **ResponseFactory** → produit des réponses **HTML / JSON / problem+json** ; gère `redirect_on_ban` ; ajoute l’entête `X-Geolocator-Simulate`.  
- **BanManager & StorageInterface** → compteur de tentatives, bans (TTL), stats ; backends : Redis / Doctrine / etc. via `StorageFactory`.  
- **ExclusionManager** → exclusions temporaires selon clé (IP / session …).  
- **RateLimiterGuard** → intégration optionnelle avec le rate limiter Symfony.  
- **FilterChain** → exécute les filtres taggés `neox_fire_geolocator.filter` par priorité.

### 🧩 Filtres intégrés (priorités dans `services.yaml`)
- **IpFilter** (400)  
- **VpnFilter** (300)  
- **NavigatorFilter** (200)  
- **CountryFilter** (150)  
- **CrawlerFilter** (100)

### 🛠️ Profiler & Templates
- Profiler : `NeoxFireGeolocatorDataCollector` (template `@NeoxFireGeolocator/Collector/geolocator.html.twig`).  
- Templates : namespace Twig **"NeoxFireGeolocator"** enregistré ; réponses par défaut via `@Geolocator/...` (templates personnalisables côté app).

---

## 🔄 Ordre d’évaluation des règles

1. Exemption de routes (`trusted.routes`)  
2. Cache session/IP du contexte provider  
3. Exclusions (`ExclusionManager`)  
4. Rate limiting (`RateLimiterGuard`)  
5. Ban actif (`BanManager`)  
6. Erreur provider (`block_on_error`)  
7. Chaîne de filtres (**deny premier gagnant**, *allow explicite mémorisé*)  

---

## 🔗 Liens utiles
- [Configuration](./config.fr.md)  
- [Providers & DSN](./providers.fr.md)  
- [Filtres](./filters.fr.md)  
- [Filtre personnalisé](./custom-filter.fr.md)  
- [Commandes CLI](./commands.fr.md)  
- [Attributs PHP](./attributes.fr.md)  
- [Usage avancé](./usage.fr.md)  
- [Réponses & formats](./responses.fr.md)  

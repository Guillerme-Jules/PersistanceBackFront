# Vents solaires — Backend (Symfony)

Application d'interrogation de données historiques de vents solaires (DSCOVR).
Backend Symfony 7.4 ; front Vue.js (séparé) qui consomme l'API REST + Mercure.

## Architecture

| Brique | Rôle | Techno |
|---|---|---|
| **ClickHouse** | Données vents solaires (~300M lignes, 1/s) en lecture analytique | `solar_wind` (MergeTree) |
| **PostgreSQL `app`** (EM `default`) | Utilisateurs + historique de recherche | Doctrine ORM |
| **PostgreSQL `audit`** (EM `audit`) | Journal central : tout événement | Doctrine ORM |
| **Mercure** | Push temps réel des résultats (1 souscription/utilisateur) | Hub SSE |

Patterns : **CQRS** (bus command/query Messenger), **recherche asynchrone** (worker),
**Strategy** (un `SearchExecutor` par type de recherche), **Repository**, value objects
immuables (`SearchCriteria`, `SearchResult`).

### Résilience hors-ligne (côté back)
- Soumission **idempotente** : l'`id` (UUID) de la recherche est fourni par le client.
  Re-poster le même id renvoie la recherche existante → les recherches mises en file
  hors-ligne sont rejouées sans doublon à la reconnexion.
- Recherche **asynchrone** : exécutée sur un worker, le résultat est persisté. Une
  déconnexion pendant le calcul n'interrompt rien ; le client récupère le résultat à la
  reconnexion (push Mercure rejoué via `Last-Event-ID`, ou simple `GET`).
- L'**aperçu** (≤ 20 lignes) + `rowCount` sont stockés → affichables hors-ligne, avec
  indication `truncated` si le total dépasse 20 lignes.

## Démarrage

```bash
docker compose up -d                      # postgres (app+audit), clickhouse, mercure, mailer
composer install
php bin/console lexik:jwt:generate-keypair --skip-if-exists   # ou openssl (voir config/jwt)
php bin/console doctrine:migrations:migrate -n --em=default    # schéma app (user, search, messenger)
php bin/console doctrine:schema:create --em=audit             # schéma journal
php bin/console app:solarwind:import --month=202406 --truncate # importer des données (ex: juin 2024)
symfony serve -d                          # ou: php -S 127.0.0.1:8000 -t public
php bin/console messenger:consume async -vv                   # worker (exécute les recherches)
```

Import complet : `php bin/console app:solarwind:import` (124 fichiers, ~7,5 Go).
Filtres : `--year=2024`, `--month=202406`.

## Tester l'API soi-même (Swagger / Nelmio)

Interface interactive : **http://127.0.0.1:8000/doc** (publique, hors firewall).

1. Démarrer : `docker compose up -d`, `symfony serve -d`, et le worker
   `php bin/console messenger:consume async -vv` (sinon les recherches restent `pending`).
2. Importer des données : `php bin/console app:solarwind:import --month=202406 --truncate`.
3. Sur `/doc` :
   - `POST /api/register` → créer un compte (`email`, `password`).
   - `POST /api/login` → récupérer le `token`.
   - Bouton **Authorize** (en haut à droite) → coller le token → toutes les routes protégées
     sont désormais testables via **Try it out**.
   - `POST /api/search` (exemples pré-remplis), puis `GET /api/search/{id}` pour voir le résultat.

OpenAPI brut : `http://127.0.0.1:8000/doc.json` (ou `php bin/console nelmio:apidoc:dump`).

CORS déjà configuré (nelmio/cors-bundle) pour le front Vue (`localhost`/`127.0.0.1`, tout port).

## API REST

Auth par **JWT** (header `Authorization: Bearer <token>`).

| Méthode | Route | Description |
|---|---|---|
| POST | `/api/register` | `{email, password}` → crée un compte |
| POST | `/api/login` | `{email, password}` → `{token}` (JWT) |
| GET | `/api/me` | Profil + coordonnées Mercure (`hub`, `topic`) |
| GET | `/api/search?limit=&offset=` | Historique (récent d'abord) |
| POST | `/api/search` | Soumet une recherche (202) — voir payload ci-dessous |
| GET | `/api/search/{id}` | État + résultat d'une recherche |
| POST | `/api/search/{id}/replay` | Relance (nouvelle entrée d'historique) |
| POST | `/api/events` | Journalise les événements client (lot) |

### Payload de recherche (`POST /api/search`)

`id` optionnel (UUID, recommandé pour l'idempotence offline). `type` requis.

```jsonc
// Bz moyen le 9 juin 2024
{ "type": "average_metric_on_day", "metric": "bz", "from": "2024-06-09" }

// Quand Bz < -40
{ "type": "threshold_crossing", "metric": "bz", "operator": "<", "threshold": -40,
  "from": "2024-06-01", "to": "2024-06-30" }

// Vitesse moyenne par tranche de 12h
{ "type": "bucket_average", "metric": "speed", "bucketHours": 12,
  "from": "2024-06-09", "to": "2024-06-11" }

// Données brutes sur une plage
{ "type": "raw_range", "from": "2024-06-09T00:00:00", "to": "2024-06-09T00:05:00" }
```

`metric` ∈ `speed | density | bt | bz`. `operator` ∈ `< <= > >= =`.

### Réponse recherche

```jsonc
{
  "id": "uuid", "label": "...", "type": "...", "params": {...},
  "status": "pending | running | done | failed",
  "createdOffline": false,
  "result": {                          // null tant que non terminé
    "summary": {...},                  // réponse synthétique (moyenne, nb d'intervalles…)
    "columns": [...], "preview": [...], // aperçu ≤ 20 lignes
    "rowCount": 1234, "truncated": true,
    "durationMs": 46
  },
  "error": null, "createdAt": "...", "executedAt": "..."
}
```

### Temps réel (front Vue)

`GET /api/me` renvoie `mercure.hub` et `mercure.topic`. Le front s'abonne **une fois** :

```js
const { hub, topic } = (await api.get('/api/me')).data.mercure;
const es = new EventSource(`${hub}?topic=${encodeURIComponent(topic)}`);
es.onmessage = e => updateSearch(JSON.parse(e.data)); // même forme que la réponse REST
```

À la reconnexion, passer le dernier `Last-Event-ID` pour rejouer les résultats manqués.

### Événements client (`POST /api/events`)

Versés au journal central. Actions autorisées : `client.offline_usage`,
`client.unexpected_disconnect`, `client.reconnect`.

```jsonc
{ "events": [ { "action": "client.offline_usage", "message": "...", "context": {...} } ] }
```

## Journal central

Tout transite par le service `Journal` → canal Monolog `audit` → table `audit_log`
(base `audit`) + fichier `var/log/audit.log`. Sont tracés : recherches (créée / démarrée
/ terminée / échec), authentifications, exceptions système (HTTP 5xx), et les événements
clients (usage hors-ligne, déconnexions).

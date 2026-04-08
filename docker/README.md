# StarRate – E2E-Tests auf donkeykong

## Voraussetzungen

- **donkeykong** hat Docker + docker compose installiert
- Das StarRate-Repo ist auf donkeykong geklont (z.B. `/opt/starrate`)
- Nextcloud läuft unter `https://cloud.mischler.info` (oder `192.168.2.205`)

## Einmaliges Setup

### 1. Nextcloud vorbereiten

Auf dem Host, auf dem der NC-Container läuft:

```bash
# Container-Name anpassen (z.B. "nextcloud" oder "nc-app")
bash scripts/setup-nc-for-e2e.sh nextcloud admin DEIN_PASSWORT
```

Das Script:
- Setzt Trusted Domains (`localhost`, `cloud.mischler.info`, `192.168.2.205`)
- Legt Testbenutzer `user2` an
- Erstellt Ordner `/Fotos/E2E-Test` und `/Fotos/Shared`
- Lädt 5 Test-JPEGs hoch

### 2. ENV-Datei anlegen

```bash
cp docker/cypress.env.example docker/cypress.env
# Datei bearbeiten und echte Passwörter eintragen
nano docker/cypress.env
```

**Wichtig:** `docker/cypress.env` enthält Passwörter — nicht in Git committen!

## Tests starten

```bash
# Auf donkeykong, im Repo-Verzeichnis:
docker compose -f docker/cypress.yml up

# Ergebnisse:
# tests/results/cypress/screenshots/   – Fehler-Screenshots
# tests/results/cypress/videos/        – Videoaufnahmen
```

## NC nur per IP erreichbar?

Falls `cloud.mischler.info` intern nicht auflöst, in `docker/cypress.yml` diese Zeilen auskommentieren:

```yaml
extra_hosts:
  - "cloud.mischler.info:192.168.2.205"
```

## Trusted Domain manuell setzen

Direkt im NC-Container (ohne das Setup-Script):

```bash
docker exec --user www-data nextcloud php occ \
  config:system:set trusted_domains 2 --value=192.168.2.205
```

## Einzelnen Test laufen lassen

```bash
docker compose -f docker/cypress.yml run --rm cypress \
  cypress run --spec "tests/e2e/starrate.cy.js" \
  --env NC_URL=https://cloud.mischler.info,NC_USER=admin,NC_PASS=xxx
```

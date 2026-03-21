# StarRate

**Professionelles Fotobewertungs-Tool für Nextcloud** – inspiriert von Lightroom Classic.

StarRate ermöglicht es Fotografen, ihre Bilder direkt in Nextcloud mit Sternebewertungen (0–5), Farbmarkierungen und Pick/Reject-Flags zu versehen. Die Bewertungen werden sowohl in Nextcloud Collaborative Tags als auch direkt in die JPEG-XMP-Metadaten geschrieben. Über den Lightroom-Sync-Panel können Bewertungen bidirektional mit einem lokalen Lightroom-Katalog abgeglichen werden.

---

## Funktionen

| Feature | Beschreibung |
|---|---|
| **Sternebewertung** | 0–5 Sterne, Hover-Preview, Tastaturkürzel (0–5) |
| **Farbmarkierungen** | Red / Yellow / Green / Blue / Purple (Kürzel 6–9) |
| **Pick / Reject** | Markierung mit P / X |
| **XMP-Metadaten** | Bewertungen werden direkt ins JPEG geschrieben (APP1) |
| **XMP-Sidecar** | Für CR3/RAW-Dateien werden `.xmp`-Begleiter erzeugt |
| **Filterliste** | Kombinierbare Filter nach Sternen, Farbe, Pick/Reject |
| **Lupenansicht** | Zoom 25–400 %, Pan, Pinch-to-Zoom, Tastaturnavigation |
| **Stapelbewertung** | Shift+Klick / Strg+Klick, Ctrl+A, schwebendes SelectionBar |
| **Gast-Freigabe** | Öffentliche Galerie-Links mit optionalem Passwortschutz |
| **Lightroom-Sync** | Bidirektionaler XMP-Sync, Konflikterkennung per mtime |
| **Dark Theme** | Anthrazit-UI (#1a1a2e) mit rotem Akzent (#e94560) |
| **i18n** | Deutsch (primär) und Englisch |

---

## Systemvoraussetzungen

- **Nextcloud** 31 oder 32 (Hub 10)
- **PHP** 8.1 – 8.4
- **Node.js** 18+ / npm 9+
- **Composer** 2

---

## Installation

### 1. Quellcode holen

```bash
git clone https://github.com/youruser/starrate.git /var/www/nextcloud/apps/starrate
cd /var/www/nextcloud/apps/starrate
```

### 2. Abhängigkeiten installieren

```bash
make install-deps
# oder manuell:
composer install --no-dev
npm ci
```

### 3. JavaScript-Bundle bauen

```bash
make build
# oder:
npm run build
```

### 4. App in Nextcloud aktivieren

```bash
sudo -u www-data /var/www/nextcloud/occ app:enable starrate
```

Alternativ: Nextcloud Admin-Bereich → Apps → „StarRate" aktivieren.

### 5. Git-Hooks einrichten (Entwicklung)

```bash
make hooks
```

Dies installiert einen **pre-commit**-Hook (PHP-Syntax + Unit-Tests) und einen **pre-push**-Hook (vollständige Testsuite + Build).

---

## Entwicklung

### Abhängigkeiten

```bash
make install-deps
```

### Tests ausführen

```bash
make test          # PHPUnit + Vitest
make test-php      # nur PHPUnit
make test-js       # nur Vitest
make test-e2e      # Cypress (Nextcloud muss laufen)
make test-coverage # Berichte nach tests/results/
```

### Einzelne Testdatei

```bash
# PHPUnit
php vendor/bin/phpunit tests/Unit/Service/ExifServiceTest.php

# Vitest (watch)
npm run test -- tests/js/RatingStars.spec.js
```

### Fixtures neu generieren

```bash
make fixtures
# oder direkt:
python3 tests/fixtures/generate_fixtures.py
```

### Lint

```bash
make lint          # PHP_CodeSniffer + ESLint
```

---

## Verzeichnisstruktur

```
starrate/
├── appinfo/
│   ├── info.xml             # App-Manifest (NC-Metadaten, Routen)
│   └── routes.php           # API-Routen
├── css/
│   └── starrate.css         # Dark-Theme Basisstile
├── js/                      # Vite-Build-Output (nicht einchecken)
├── l10n/
│   ├── de.js / de.json      # Deutsche Übersetzungen
│   └── en.js / en.json      # Englische Übersetzungen
├── lib/
│   ├── Controller/          # OCA HTTP-Controller
│   ├── Migration/           # Datenbank-Migration (InstallStep)
│   ├── Service/             # Business-Logik
│   └── Settings/            # Nutzereinstellungen
├── scripts/
│   └── hooks/               # Git-Hook-Quellen
├── src/
│   ├── components/          # Vue-Komponenten
│   ├── views/               # Vue-Views (Gallery, Sync)
│   ├── main.js              # Haupt-App-Einstiegspunkt
│   └── guest.js             # Gast-Galerie-Einstiegspunkt
├── templates/
│   ├── index.php            # Haupt-SPA-Template
│   ├── guest.php            # Gast-Galerie (kein NC-Layout)
│   └── settings/
│       └── personal.php     # Persönliche Einstellungen
├── tests/
│   ├── Unit/                # PHPUnit-Tests
│   ├── e2e/                 # Cypress-Tests
│   ├── fixtures/            # Binäre Test-Fixtures
│   ├── js/                  # Vitest-Komponententests
│   └── results/             # Test-Ausgaben, Coverage
├── composer.json
├── package.json
├── phpunit.xml
├── vite.config.js
├── vitest.config.js
└── Makefile
```

---

## Konfiguration

### Persönliche Einstellungen

Über **Nextcloud → Einstellungen → Persönlich → StarRate**:

| Einstellung | Standard | Beschreibung |
|---|---|---|
| `default_sort` | `name` | Sortierung: `name`, `date`, `rating`, `color` |
| `thumbnail_size` | `200` | Thumbnail-Breite in Pixel (120–600) |
| `write_exif` | `true` | XMP direkt ins JPEG schreiben |
| `show_filename` | `true` | Dateinamen im Grid anzeigen |
| `show_rating_overlay` | `true` | Sterne-Overlay im Grid |
| `show_color_overlay` | `true` | Farb-Overlay im Grid |
| `grid_columns` | `auto` | Spaltenanzahl (`auto` oder 2–8) |

### Umgebungsvariablen (E2E-Tests)

```bash
NC_URL=http://localhost:8080
NC_USER=admin
NC_PASS=admin
NC_USER_B=user2
NC_PASS_B=user2
```

---

## Gast-Freigabe

1. Ordner öffnen → Share-Button klicken
2. Berechtigung **„Bewerten"** aktivieren
3. Optional: Passwort und Ablaufdatum setzen
4. Link kopieren und teilen

Gäste können ohne Nextcloud-Account Bilder bewerten. Der Fotograf sieht alle Gast-Bewertungen in der Sidebar.

---

## Lightroom-Sync

1. **StarRate** → **Sync** → **Zuordnung hinzufügen**
2. Nextcloud-Pfad und lokalen Lightroom-Ordner eingeben
3. Richtung wählen: NC→LR / LR→NC / Bidirektional
4. **Sync starten**

Der Sync schreibt `.xmp`-Sidecar-Dateien für RAW-Dateien (CR3, NEF, ARW …) im Lightroom-kompatiblen Format. Bei bidirektionalem Sync gewinnt die Datei mit dem neueren `mtime`.

---

## API-Übersicht

Alle Endpunkte unter `/apps/starrate/api/`:

| Methode | Pfad | Beschreibung |
|---|---|---|
| `GET` | `images` | Bilder eines Ordners mit Metadaten |
| `GET` | `thumbnail/{fileId}` | JPEG-Thumbnail (gecacht) |
| `GET` | `rating/{fileId}` | Bewertung eines Bildes |
| `POST` | `rating/{fileId}` | Bewertung setzen |
| `POST` | `rating/batch` | Stapel-Bewertung |
| `DELETE` | `rating/{fileId}` | Bewertung löschen |
| `GET` | `share` | Eigene Freigaben |
| `POST` | `share` | Neue Freigabe erstellen |
| `PUT` | `share/{token}` | Freigabe bearbeiten |
| `DELETE` | `share/{token}` | Freigabe löschen |
| `GET` | `guest/{token}/images` | Gast: Bilder laden |
| `POST` | `guest/{token}/rate` | Gast: Bild bewerten |
| `GET` | `sync/mappings` | Sync-Zuordnungen |
| `POST` | `sync/mappings` | Zuordnung anlegen |
| `PUT` | `sync/mappings/{id}` | Zuordnung bearbeiten |
| `DELETE` | `sync/mappings/{id}` | Zuordnung löschen |
| `POST` | `sync/run/{id}` | Sync starten |
| `GET` | `settings` | Nutzereinstellungen |
| `POST` | `settings` | Einstellungen speichern |

---

## Erstellen eines Release-Pakets

```bash
make package
# → dist/starrate.tar.gz
```

Das Paket enthält nur die für den Betrieb notwendigen Dateien (kein `node_modules`, kein `vendor`, keine Tests).

---

## Lizenz

AGPL-3.0-or-later – siehe [COPYING](COPYING).

---

## Mitwirken

Pull Requests und Bug-Reports sind willkommen. Bitte vor dem Einreichen sicherstellen:

```bash
make test    # alle Tests grün
make lint    # kein Lint-Fehler
make build   # Build erfolgreich
```

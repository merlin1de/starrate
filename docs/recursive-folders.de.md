# Rekursive Ordneransicht

> English version: [recursive-folders.en.md](recursive-folders.en.md)

Standardmäßig zeigt StarRate nur Bilder direkt im aktuellen Ordner. Die rekursive Ansicht blendet zusätzlich alle Bilder aus Unterordnern ein und sortiert sie als einen flachen Stream.

## Einschalten

In den persönlichen Einstellungen unter **Persönlich → StarRate → Rekursive Ansicht**:

1. **„Rekursive Ansicht aktivieren"** anhaken — schaltet das Feature für deinen Account frei.
2. Erst danach erscheinen weitere Optionen und der Schalter in der Galerie.

Solange die Master-Option aus ist, verhält sich StarRate exakt wie bisher (nur direkter Ordner-Inhalt).

## Konfigurieren

Drei Stellen, an denen rekursiv/Tiefe gesetzt werden können — Reihenfolge der Auswertung von oben nach unten:

| Quelle | Wirkung |
|---|---|
| **URL-Query** (`?recursive=1&depth=2`) | Höchste Priorität, überschreibt alles. Praktisch zum Teilen einer konkreten Ansicht. |
| **Pro Ordner** (Galerie-Toolbar) | Wird gemerkt — beim nächsten Öffnen desselben Ordners ist die letzte Wahl wieder aktiv. |
| **Standard** (Einstellungen) | Greift, wenn weder URL noch Folder-Cache etwas vorgeben. |

In den Einstellungen lassen sich zwei Defaults setzen:

- **Standardmäßig rekursiv** — neue Ordner öffnen sich direkt rekursiv. Aus, wenn du dich primär ordnerweise durcharbeitest.
- **Gruppen-Tiefe** — Sortier-Modus innerhalb der rekursiven Ansicht (siehe unten).

## Gruppen-Tiefe — was passiert da?

Die Tiefe ist ein **Sortier-Modifier**, kein Layout-Feature. Es gibt keine sichtbaren Gruppen-Header, keine Trennlinien — nur die Reihenfolge ändert sich, sodass Bilder mit identischem Pfad-Präfix nebeneinander landen.

Beispiel-Ordnerbaum:

```
/Hochzeit
├── Vorbereitung/
│   ├── IMG_001.jpg
│   └── IMG_002.jpg
├── Trauung/
│   └── IMG_003.jpg
└── Empfang/
    ├── IMG_004.jpg
    └── Tanz/
        └── IMG_005.jpg
```

Du öffnest `/Hochzeit` rekursiv. Sekundärsortierung ist die in den NC-Einstellungen gewählte (z.B. Dateiname).

| Tiefe | Reihenfolge | Effekt |
|---|---|---|
| **0 (Flach)** | 001, 002, 003, 004, 005 | Reine User-Sortierung, Pfad ignoriert. |
| **1** | 001, 002 \| 003 \| 004, 005 | Sortiert primär nach 1. Sub-Ordner: alle aus `Vorbereitung`, dann `Trauung`, dann `Empfang/*`. |
| **2** | 001, 002 \| 003 \| 004 \| 005 | Sortiert nach 2 Pfad-Segmenten: `Empfang/Tanz` ist jetzt eigener Block, getrennt von `Empfang`. |
| **3–4** | 001, 002 \| 003 \| 004 \| 005 | Wie Tiefe 2, da der Baum nicht tiefer wird. |

Gut zu wissen:

- Beim Filtern (Sterne, Farben, Pick) wirken Filter **vor** der Tiefen-Sortierung — du siehst also nur passende Bilder, immer noch nach Präfix gruppiert.
- Tiefe = 0 ist meist die richtige Wahl bei chronologischer Sortierung („alles vom Wochenende, egal aus welchem Ordner").
- Tiefe ≥ 1 hilft, wenn du beim Scrollen in den Ordner-Kontext zurückfinden willst (Vorbereitung vor Trauung vor Empfang).

## Folder-Cache

Pro Ordner merkt sich StarRate im Browser-`localStorage`, ob du ihn rekursiv geöffnet hast und mit welcher Tiefe.

- Speicher-Key: `starrate_folder_recursive_v1`
- Maximal **50 Ordner** — danach LRU-Verdrängung (zuletzt gesehener Ordner gewinnt).
- Browser-lokal, **nicht** mit dem NC-Account synchronisiert. Auf einem anderen Gerät startest du wieder mit den Defaults.
- Browser-Daten löschen → Cache weg → Defaults greifen wieder.

Sinnvoll: in einem Hochzeits-Ordner einmal rekursiv schalten, beim nächsten Besuch ist es wieder so. Gleichzeitig bleibt z.B. ein „Posteingang"-Ordner nicht-rekursiv, wenn du ihn so konfiguriert hast.

## Gast-Share

Beim Erstellen oder Bearbeiten eines Gast-Links lässt sich die rekursive Ansicht **fest mit dem Share verdrahten** — unabhängig davon, was du selbst lokal eingestellt hast.

Im Share-Dialog (nur sichtbar, wenn du die rekursive Ansicht in den Einstellungen aktiviert hast):

- **„Rekursiv"** — Gast sieht alle Bilder unterhalb des freigegebenen Ordners.
- **„Gruppen-Tiefe"** — Sortier-Modus für den Gast (gleiche Semantik wie oben).

Anwendungsfälle:

- Hochzeitspaar bekommt einen Link auf `/Hochzeit` rekursiv mit Tiefe 1 → sieht alle Bilder, gruppiert nach Vorbereitung/Trauung/Empfang.
- Modell bekommt einen Link auf `/Shooting/2026-04-28` ohne rekursiv → sieht nur den Tageshaufen, keine angrenzenden Sessions.
- Eine bereits gegebene Freigabe lässt sich nachträglich von „flach" auf „rekursiv mit Tiefe 2" umstellen, ohne dass sich der Link für den Gast ändert.

Der Gast hat selbst **keinen** Schalter — was du im Share-Dialog wählst, sieht er.

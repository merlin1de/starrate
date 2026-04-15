# Changelog

## 1.2.9

### EN

A big leap since v1.1.0 — over 100 commits of new features, stability and polish. Highlights:

**New features**
- **Comments** — photographers and guests can leave comments on individual images. Great for client feedback rounds or quick self-notes. Toggleable per user, works in guest galleries too.
- **Export rating list** — CSV export of all rated images with selectable columns (filename, stars, color, pick/reject). Download or copy to clipboard. Guest export can be enabled per share.
- **Batch pick/reject** — select multiple images and flag them all as pick or reject in one click.
- **XMP import via occ** — new `occ starrate:import-xmp` command reads existing XMP ratings from Lightroom, digiKam & co. into StarRate. Use `--recursive` for whole folder trees.
- **Write-XMP as a user setting** — users who don't want XMP written into JPEGs can now disable it per account.
- **Guest logins in the share log** — sign-ins and failed password attempts now appear alongside ratings.

**UI & ergonomics**
- **Tidier nav row** — Share and Export moved into the filter bar; subfolders got their own row (desktop) or a popover menu (mobile). Breadcrumb is readable again.
- **Mobile polish** — filter bar scrolls horizontally cleanly, loupe rating footer no longer overlaps the image, deep paths truncate correctly.
- **ESC reliably closes modals** — no more accidental folder navigation when closing share/export dialogs.
- **Image count always visible** — you see how many images are in the folder even without an active filter.
- **Loupe performance** — preview preload, touch-zoom safety, less flicker when entering from the grid.

**Stability & compatibility**
- **Broader XMP compatibility** — `xap:` alias (Nokia Lumia, older Olympus), digiKam `ColorLabel`, self-closing `rdf:Description`, multi-block files.
- **Lightroom XMP fields preserved** on rating updates (was a regression in 1.1, issue #16).
- **Debounced batch ratings** — prevents concurrent JPEG writes when clicking fast. Info toasts surface skipped files.
- **Security** — guest comment endpoints hardened, rating requests now verify the file actually belongs to the share.
- **Rate limiting** — guest previews allow 600/min for smooth scrolling through large galleries.

**Under the hood**
- Test coverage gates: Vitest with coverage threshold, PHPUnit on PHP 8.1 + 8.3, Cypress E2E suite grown to 72 passing tests.
- Hardened CI: releases only from the correct branch, nightlies no longer auto-promote to the app store.
- Nextcloud 33 supported.

### DE

Ein großer Sprung seit v1.1.0 — über 100 Commits voll neuer Features, Stabilität und Politur. Die Highlights:

**Neue Features**
- **Kommentare** — Fotografen und Gäste können jetzt Kommentare zu einzelnen Bildern hinterlassen. Ideal für Feedback-Runden mit Kunden oder als Notiz an sich selbst. Ein- und ausschaltbar pro Benutzer, funktioniert auch in Gast-Galerien.
- **Bewertungsliste exportieren** — CSV-Export aller bewerteten Bilder mit wählbaren Spalten (Dateiname, Sterne, Farbe, Pick/Reject). Herunterladen oder in die Zwischenablage kopieren. Gast-Export kann pro Share erlaubt werden.
- **Batch Pick/Reject** — Mehrere Bilder markieren, mit einem Klick alle als Pick oder Reject flaggen.
- **XMP-Import via occ** — Neuer Befehl `occ starrate:import-xmp` liest bestehende XMP-Bewertungen aus Lightroom, digiKam & Co. in StarRate ein. Mit `--recursive` für ganze Ordnerbäume.
- **XMP-Write als Nutzer-Einstellung** — wer XMP nicht in JPEGs schreiben möchte, kann das jetzt pro Benutzer abschalten.
- **Gast-Login im Share-Log** — Anmeldungen und Fehlversuche tauchen jetzt im Protokoll auf, nicht nur Bewertungen.

**UI & Bedienung**
- **Aufgeräumte Nav-Zeile** — Teilen und Export sind in die FilterBar gewandert, Unterordner erscheinen als eigene Zeile (Desktop) bzw. als Popover-Menü (Mobile). Breadcrumb ist dadurch wieder lesbar.
- **Mobile-Polish** — FilterBar scrollt sauber horizontal, der Bewertungs-Footer in der Loupe überlappt das Bild nicht mehr, tiefe Pfade werden korrekt abgeschnitten.
- **ESC schließt Modals zuverlässig** — keine versehentliche Ordner-Navigation mehr, wenn man Share- oder Export-Dialog schließt.
- **Bildzähler immer sichtbar** — auch ohne aktiven Filter sieht man, wie viele Bilder im Ordner liegen.
- **Loupe-Performance** — Preview-Preload, Touch-Zoom-Schutz, weniger Flackern beim Einstieg aus dem Grid.

**Stabilität & Kompatibilität**
- **XMP-Kompatibilität erweitert** — `xap:`-Alias (Nokia Lumia, ältere Olympus), digiKam `ColorLabel`, selbst-schließendes `rdf:Description`, Multi-Block-Dateien.
- **Lightroom-XMP-Felder** bleiben bei Rating-Updates erhalten (war Bug in 1.1, Issue #16).
- **Batch-Ratings debounced** — verhindert konkurrierende JPEG-Writes beim schnellen Klicken. Sichtbare Info-Toasts für Skips.
- **Sicherheit** — Gast-Kommentar-Endpoints gehärtet, Rating-Requests prüfen jetzt, dass die Datei tatsächlich zum Share gehört.
- **Rate Limiting** — Gast-Previews erlauben 600/min für flüssiges Durch-Scrollen großer Galerien.

**Unter der Haube**
- Umfassende Test-Coverage-Gates: Vitest mit Coverage-Threshold, PHPUnit auf PHP 8.1 + 8.3, Cypress-E2E-Suite auf 72 grüne Tests ausgebaut.
- CI-Workflow gehärtet: Release nur aus korrekten Branches, Nightly geht per Default nicht mehr automatisch in den Store.
- Nextcloud 33 unterstützt.

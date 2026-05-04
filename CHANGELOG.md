# Changelog

## Unreleased

### EN

**New features**
- **Color rating compatible with: Lightroom (DE) / Bridge / digiKam** (#69, #70) — new personal setting that controls the language of `xmp:Label`. With "Lightroom (German localization)" StarRate writes `xmp:Label="Rot"` etc. so a German LRC matches its native label set strings instead of falling through to "Custom" with a white flag. With "Bridge / digiKam / English tools" it writes `xmp:Label="Red"` etc. Default derives from the NC user UI language (`de_*` → DE, otherwise EN), so German users get a working LRC import out of the box. The companion `photoshop:LabelColor` field stays lowercase English regardless — that's universal and drives the LR color stripe.
- **Pick / Reject is now written into XMP** (#70) — previously stored only in NC tags. Bridge/LRC standard format: pick → `xmpDM:pick="1"` + `xmpDM:good="true"`, reject → `xmpDM:pick="-1"` + `xmpDM:good="false"`, none → both attributes removed. Read path is backward-compatible with the older FlashView schema (`flashView:IsPicked`/`IsRejected`); the write path actively cleans up those legacy attributes including the orphan `xmlns:flashView` namespace declaration.

**Bug fixes**
- **LR re-imports the wrong color label after editing in StarRate** (#70) — Lightroom Classic 7+ reads `photoshop:LabelColor` (lowercase EN) with priority over `xmp:Label` to derive the color stripe. StarRate previously only wrote `xmp:Label`, and the patch path did not strip a stale `photoshop:LabelColor` left behind by LR — so a re-import in LR showed the old color, ignoring the change. StarRate now writes both fields in lockstep and removes any stale `photoshop:LabelColor` before re-injecting.
- **Grid only fills 2/3 of the viewport after navigating into a subfolder** (#71) — when SPA-navigating between folders, the header above the grid changed (subfolder pills appearing/disappearing), which moved the grid vertically without changing its own size. The existing `ResizeObserver` on the grid stayed silent and the cached `max-height` was wrong for the new layout, requiring a manual reload. Now the observer also watches the grid's wrap parent, so any header-size change re-runs the height calculation.

### DE

**Neue Features**
- **Farb-Bewertungen kompatibel mit: Lightroom (DE) / Bridge / digiKam** (#69, #70) — neue persönliche Einstellung, die die Sprache von `xmp:Label` steuert. Mit „Lightroom (deutsche Lokalisierung)" schreibt StarRate `xmp:Label="Rot"` etc., damit ein deutsches LRC den nativen Label-Set-String matcht und nicht auf „Custom" mit weißer Fahne durchfällt. Mit „Bridge / digiKam / englische Tools" wird `xmp:Label="Red"` etc. geschrieben. Default leitet sich aus der NC-UI-Sprache ab (`de_*` → DE, sonst EN) — deutsche User bekommen einen funktionierenden LRC-Import direkt out of the box. Das parallele Feld `photoshop:LabelColor` bleibt unabhängig davon immer in lowercase EN — das ist universell und treibt den Farbstreifen in LR.
- **Pick / Reject werden jetzt ins XMP geschrieben** (#70) — bislang nur als NC-Tag gespeichert. Bridge/LRC-Standardformat: Pick → `xmpDM:pick="1"` + `xmpDM:good="true"`, Reject → `xmpDM:pick="-1"` + `xmpDM:good="false"`, none → beide Attribute komplett entfernt. Der Lesepfad bleibt rückwärtskompatibel zum älteren FlashView-Schema (`flashView:IsPicked`/`IsRejected`); der Schreibpfad räumt diese Legacy-Attribute inklusive verwaister `xmlns:flashView`-Namespace-Deklaration aktiv weg.

**Bugfixes**
- **LR re-importiert die falsche Farbe nach Änderung in StarRate** (#70) — Lightroom Classic 7+ liest `photoshop:LabelColor` (lowercase EN) mit Priorität über `xmp:Label`, um den Farbstreifen abzuleiten. StarRate hat bisher nur `xmp:Label` geschrieben, und der Patch-Pfad hat ein von LR hinterlassenes `photoshop:LabelColor` nicht entfernt — beim Re-Import in LR wurde die alte Farbe angezeigt, unsere Änderung ignoriert. StarRate schreibt jetzt beide Felder parallel und räumt ein stale `photoshop:LabelColor` vor dem Re-Inject weg.
- **Grid füllt nur 2/3 der Bildschirmhöhe nach Wechsel in einen Unterordner** (#71) — bei SPA-Navigation zwischen Ordnern änderte sich der Header oberhalb des Grids (Subfolder-Pills tauchten auf oder verschwanden), das Grid wurde vertikal verschoben, behielt aber seine eigene Größe. Der bestehende `ResizeObserver` auf dem Grid feuerte daher nicht und die gecachte `max-height` war für das neue Layout falsch — ein manueller Reload war nötig. Der Observer beobachtet jetzt zusätzlich das Wrap-Parent-Element, sodass jede Header-Größenänderung die Höhenberechnung neu auslöst.

## 1.2.11

### EN

**Performance**
- **Faster thumbnail loading** — the grid now fetches previews via NC's core `/core/preview` endpoint for logged-in users instead of going through the StarRate controller. Less PHP overhead per request, more cache hits.

**Bug fixes**
- **Thumbnails sometimes invisible until window re-shown** — fixed a paint-suppression bug where loaded grid thumbnails wouldn't appear until the browser window was occluded and re-exposed. Two interacting causes: the native `loading="lazy"` attribute fought against StarRate's own intersection-based preload queue, and `decoding="async"` let the browser defer image decoding so `<img>` elements landed in the DOM but didn't paint until a window-visibility change forced a full repaint. Removed the redundant `loading` attribute and now pre-decode preloaded images via `HTMLImageElement.decode()` before flipping `thumbLoaded` — the `<img>` is added to the DOM only once the bitmap is decode-ready and paints in the same frame.

### DE

**Performance**
- **Schnelleres Thumbnail-Laden** — das Grid lädt Previews jetzt für eingeloggte User über NCs `/core/preview`-Endpunkt statt über den StarRate-Controller. Weniger PHP-Overhead pro Request, mehr Cache-Treffer.

**Bugfixes**
- **Thumbnails manchmal unsichtbar bis Fenster neu aufgebaut** — Paint-Suppression-Bug behoben, bei dem geladene Grid-Thumbnails erst sichtbar wurden, nachdem das Browserfenster verdeckt und wieder aufgedeckt wurde. Zwei zusammenwirkende Ursachen: das native `loading="lazy"`-Attribut hat gegen StarRates eigenen Preload-Queue gearbeitet, und `decoding="async"` ließ den Browser den Decode aufschieben — `<img>`-Elemente landeten zwar im DOM, paintet wurden sie aber erst nach einem Force-Repaint via Fenster-Visibility-Wechsel. Das redundante `loading`-Attribut ist raus, und vorgeladene Bilder werden jetzt via `HTMLImageElement.decode()` pre-decoded, bevor `thumbLoaded` auf true geht — das `<img>` wandert erst dann ins DOM, wenn die Bitmap decode-ready ist, und paintet im selben Frame.

## 1.2.10

### EN

**Bug fixes**
- **Guest share: Nextcloud footer no longer overlaps images** (#62) — on public share pages NC renders an entity-name/legal/signup footer that on mobile could eat up to 1/3 of the viewport and on desktop pushed the grid up. The StarRate guest view now renders as a full-viewport overlay and hides NC's header/footer chrome (imprint/privacy remain reachable via the NC instance root). Big thanks to @matt-ek for the detailed repro and @lukegraphix for the independent reproduction on a fresh install.

### DE

**Bugfixes**
- **Gast-Freigabe: Nextcloud-Footer überlagert keine Bilder mehr** (#62) — auf Public-Share-Seiten rendert NC einen Footer mit Entity-Name, Legal-Links und Signup-Promo, der auf Mobile bis zu 1/3 des Viewports fressen konnte und auf Desktop das Grid hochgedrückt hat. Die StarRate-Gast-Ansicht legt sich jetzt als Vollbild-Overlay über die Seite und blendet NC-Header und -Footer aus (Impressum/Datenschutz bleiben über den Root der NC-Instanz erreichbar). Großer Dank an @matt-ek für die detaillierte Repro und @lukegraphix für die unabhängige Reproduktion auf einer frischen Installation.

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

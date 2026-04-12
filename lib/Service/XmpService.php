<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Erzeugt und liest XMP-Sidecar-Dateien (.xmp) für RAW-Formate (Canon CR3 etc.).
 *
 * Namenskonvention:
 *   IMG_1234.cr3  →  IMG_1234.xmp
 *   IMG_1234.jpg  →  IMG_1234.xmp  (falls kein direktes JPEG-XMP gewünscht)
 *
 * Das erzeugte XMP ist Lightroom Classic / Adobe Camera Raw kompatibel.
 * Präfix-unabhängiges Matching: nur der Basis-Dateiname (ohne Erweiterung) zählt.
 */
class XmpService
{
    // Lightroom-kompatible Label-Namen (kanonisch englisch)
    public const LABEL_MAP = [
        'Red'    => 'Red',
        'Yellow' => 'Yellow',
        'Green'  => 'Green',
        'Blue'   => 'Blue',
        'Purple' => 'Purple',
    ];

    // Lokalisierte Label-Namen → kanonisch englisch
    // photoshop:LabelColor (immer Kleinbuchstaben EN) wird per strcasecmp aufgelöst,
    // daher hier nur Varianten die NICHT bereits über LABEL_MAP greifen.
    private const LABEL_ALIASES = [
        // Deutsch (LR exportiert xmp:Label in der UI-Sprache)
        'Rot'  => 'Red',
        'Gelb' => 'Yellow',
        'Grün' => 'Green',   // UTF-8: ü = 0xC3 0xBC
        'Blau' => 'Blue',
        'Lila' => 'Purple',
    ];

    // digiKam:ColorLabel → kanonisch englisch (numerische Werte 0–10)
    // Werte ohne StarRate-Äquivalent (2=Orange, 7=Grey, 8=Black, 9=White, 10=Darkred) → null
    private const DIGIKAM_COLOR_MAP = [
        1 => 'Red',
        3 => 'Yellow',
        4 => 'Green',
        5 => 'Blue',
        6 => 'Purple',
    ];

    // RAW-Formate die per Sidecar verwaltet werden
    public const RAW_EXTENSIONS = ['cr3', 'cr2', 'nef', 'arw', 'orf', 'rw2', 'dng', 'raf', 'pef', 'srw'];

    // JPEG-Formate die direkt eingebettet werden können
    public const JPEG_EXTENSIONS = ['jpg', 'jpeg'];

    private const XMP_TEMPLATE = <<<'XMP'
<?xpacket begin='﻿' id='W5M0MpCehiHzreSzNTczkc9d'?>
<x:xmpmeta xmlns:x='adobe:ns:meta/' x:xmptk='StarRate 1.0'>
  <rdf:RDF xmlns:rdf='http://www.w3.org/1999/02/22-rdf-syntax-ns#'>
    <rdf:Description rdf:about=''
      xmlns:xmp='http://ns.adobe.com/xap/1.0/'
      %RATING_ATTR%%LABEL_ATTR%>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
<?xpacket end='w'?>
XMP;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    // ─── Sidecar schreiben ────────────────────────────────────────────────────

    /**
     * Schreibt oder aktualisiert eine .xmp-Sidecar-Datei in einem Nextcloud-Ordner.
     *
     * @param  Folder      $folder    Nextcloud-Ordner in dem die Sidecar liegt
     * @param  string      $baseName  Dateiname OHNE Erweiterung (z. B. "IMG_1234")
     * @param  int         $rating    0–5
     * @param  string|null $label     Red/Yellow/Green/Blue/Purple oder null
     * @return string                 Inhalt der geschriebenen XMP-Datei
     */
    public function writeSidecar(Folder $folder, string $baseName, int $rating, ?string $label): string
    {
        $xmpContent  = $this->buildXmpContent($rating, $label);
        $sidecarName = $baseName . '.xmp';

        try {
            if ($folder->nodeExists($sidecarName)) {
                /** @var File $existing */
                $existing = $folder->get($sidecarName);
                $existing->putContent($xmpContent);
                $this->logger->debug("StarRate: sidecar updated: {$sidecarName}");
            } else {
                $folder->newFile($sidecarName, $xmpContent);
                $this->logger->debug("StarRate: sidecar created: {$sidecarName}");
            }
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to write sidecar {$sidecarName}: " . $e->getMessage(),
                0,
                $e
            );
        }

        return $xmpContent;
    }

    // ─── Sidecar lesen ────────────────────────────────────────────────────────

    /**
     * Liest eine .xmp-Sidecar aus einem Nextcloud-Ordner.
     *
     * @return array{rating: int, label: string|null, mtime: int}|null  null wenn keine Sidecar
     */
    public function readSidecar(Folder $folder, string $baseName): ?array
    {
        $sidecarName = $baseName . '.xmp';

        try {
            if (!$folder->nodeExists($sidecarName)) {
                return null;
            }
            /** @var File $file */
            $file    = $folder->get($sidecarName);
            $content = $file->getContent();
            $parsed  = $this->parseXmpContent($content);
            $parsed['mtime'] = $file->getMtime();
            return $parsed;
        } catch (NotFoundException) {
            return null;
        } catch (\Exception $e) {
            $this->logger->warning("StarRate: failed to read sidecar {$sidecarName}: " . $e->getMessage());
            return null;
        }
    }

    // ─── Dateiname-Matching ───────────────────────────────────────────────────

    /**
     * Extrahiert den Basis-Dateinamen ohne Erweiterung und normalisiert ihn.
     * Groß-/Kleinschreibung der Erweiterung wird ignoriert.
     *
     * Beispiele:
     *   "IMG_1234.JPG"   → "IMG_1234"
     *   "IMG_1234.cr3"   → "IMG_1234"
     *   "_DSC0042.ARW"   → "_DSC0042"
     */
    public function getBaseName(string $filename): string
    {
        return pathinfo($filename, PATHINFO_FILENAME);
    }

    /**
     * Prüft ob zwei Dateinamen zum selben Shooting-Bild gehören
     * (gleicher Basis-Name, unabhängig von Erweiterung und Groß-/Kleinschreibung).
     *
     * Beispiele:
     *   ("IMG_1234.jpg", "IMG_1234.cr3")   → true
     *   ("IMG_1234.jpg", "IMG_1234.CR3")   → true
     *   ("IMG_1234.jpg", "IMG_5678.cr3")   → false
     */
    public function isSameBase(string $fileA, string $fileB): bool
    {
        return strcasecmp(
            pathinfo($fileA, PATHINFO_FILENAME),
            pathinfo($fileB, PATHINFO_FILENAME)
        ) === 0;
    }

    /**
     * Prüft ob eine Datei ein unterstütztes RAW-Format ist.
     */
    public function isRawFile(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::RAW_EXTENSIONS, true);
    }

    /**
     * Prüft ob eine Datei ein JPEG ist.
     */
    public function isJpegFile(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::JPEG_EXTENSIONS, true);
    }

    // ─── XMP aufbauen / parsen ────────────────────────────────────────────────

    /**
     * Baut den XMP-Sidecar-Inhalt als String.
     */
    public function buildXmpContent(int $rating, ?string $label): string
    {
        $ratingAttr = "xmp:Rating=\"{$rating}\"";
        $labelAttr  = $label ? "\n      xmp:Label=\"{$label}\"" : '';

        $xmp = str_replace(
            ['%RATING_ATTR%', '%LABEL_ATTR%'],
            [$ratingAttr, $labelAttr],
            self::XMP_TEMPLATE
        );

        return $xmp;
    }

    /**
     * Parst einen XMP-String und gibt Rating + Label zurück.
     *
     * Rating-Auflösung:
     *  xmp:Rating / xap:Rating (xap: ist der alte exiftool/IDimager Alias, gleiche Namespace-URI)
     *
     * Label-Auflösung (Priorität hoch → niedrig):
     *  1. photoshop:LabelColor  — LR-proprietär, immer lowercase EN (red/yellow/…)
     *  2. xmp:Label             — Standard, sprachabhängig (Red/Rot/Rouge/…)
     *  3. digiKam:ColorLabel    — numerisch (1=Red, 3=Yellow, 4=Green, 5=Blue, 6=Purple)
     *
     * @return array{rating: int, label: string|null}
     */
    public function parseXmpContent(string $xmp): array
    {
        $rating = 0;
        $label  = null;

        // xmp:Rating / xap:Rating als Attribut oder Element
        if (preg_match('/(?:xmp|xap):Rating\s*=\s*[\'"](\d+)[\'"]/', $xmp, $m)) {
            $val = (int) $m[1];
            $rating = ($val >= 0 && $val <= 5) ? $val : 0;
        } elseif (preg_match('/<(?:xmp|xap):Rating>(\d+)<\/(?:xmp|xap):Rating>/', $xmp, $m)) {
            $val = (int) $m[1];
            $rating = ($val >= 0 && $val <= 5) ? $val : 0;
        }

        // photoshop:LabelColor (Prio 1) — immer lowercase EN, z.B. "red", "green"
        if (preg_match('/photoshop:LabelColor\s*=\s*[\'"]([^\'"]+)[\'"]/', $xmp, $m)
            || preg_match('/<photoshop:LabelColor>([^<]+)<\/photoshop:LabelColor>/', $xmp, $m)) {
            $label = $this->resolveLabel(trim($m[1]));
        }

        // xmp:Label / xap:Label (Prio 2, nur wenn photoshop:LabelColor nicht aufgelöst)
        // Sprachabhängig: akzeptiert EN (Red/red/RED) und DE (Rot/Gelb/Grün/…)
        if ($label === null
            && (preg_match('/(?:xmp|xap):Label\s*=\s*[\'"]([^\'"]+)[\'"]/', $xmp, $m)
                || preg_match('/<(?:xmp|xap):Label>([^<]+)<\/(?:xmp|xap):Label>/', $xmp, $m))) {
            $label = $this->resolveLabel(trim($m[1]));
        }

        // digiKam:ColorLabel (Prio 3) — numerisch, nur wenn noch kein Label gefunden
        if ($label === null
            && (preg_match('/digiKam:ColorLabel\s*=\s*[\'"](\d+)[\'"]/', $xmp, $m)
                || preg_match('/<digiKam:ColorLabel>(\d+)<\/digiKam:ColorLabel>/', $xmp, $m))) {
            $label = self::DIGIKAM_COLOR_MAP[(int) $m[1]] ?? null;
        }

        return ['rating' => $rating, 'label' => $label];
    }

    /**
     * Normalisiert einen Label-String auf den kanonischen englischen Namen.
     * Akzeptiert:
     *  - Englisch, case-insensitiv: red/Red/RED → Red
     *  - Deutsch (LR UI-Sprache): Rot/Gelb/Grün/Blau/Lila → Red/Yellow/Green/Blue/Purple
     * Unbekannte Werte → null.
     */
    private function resolveLabel(string $val): ?string
    {
        // Exakter Treffer (kanonisch englisch)
        if (isset(self::LABEL_MAP[$val])) {
            return $val;
        }

        // Case-insensitiver englischer Fallback (z.B. "red" → "Red", "GREEN" → "Green")
        foreach (array_keys(self::LABEL_MAP) as $canonical) {
            if (strcasecmp($val, $canonical) === 0) {
                return $canonical;
            }
        }

        // Alias-Map (z.B. deutsch: "Rot" → "Red", "Grün" → "Green")
        if (isset(self::LABEL_ALIASES[$val])) {
            return self::LABEL_ALIASES[$val];
        }

        return null;
    }

    /**
     * Löscht eine Sidecar-Datei aus einem Nextcloud-Ordner (falls vorhanden).
     */
    public function deleteSidecar(Folder $folder, string $baseName): bool
    {
        $sidecarName = $baseName . '.xmp';
        try {
            if ($folder->nodeExists($sidecarName)) {
                $folder->get($sidecarName)->delete();
                return true;
            }
        } catch (\Exception $e) {
            $this->logger->warning("StarRate: failed to delete sidecar: " . $e->getMessage());
        }
        return false;
    }
}

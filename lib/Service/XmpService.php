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
    // Lightroom-kompatible Label-Namen
    public const LABEL_MAP = [
        'Red'    => 'Red',
        'Yellow' => 'Yellow',
        'Green'  => 'Green',
        'Blue'   => 'Blue',
        'Purple' => 'Purple',
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
     * @return array{rating: int, label: string|null}
     */
    public function parseXmpContent(string $xmp): array
    {
        $rating = 0;
        $label  = null;

        // xmp:Rating als Attribut oder Element
        if (preg_match('/xmp:Rating\s*=\s*[\'"](\d+)[\'"]/', $xmp, $m)) {
            $val = (int) $m[1];
            $rating = ($val >= 0 && $val <= 5) ? $val : 0;
        } elseif (preg_match('/<xmp:Rating>(\d+)<\/xmp:Rating>/', $xmp, $m)) {
            $val = (int) $m[1];
            $rating = ($val >= 0 && $val <= 5) ? $val : 0;
        }

        // xmp:Label als Attribut oder Element
        // Case-insensitiver Vergleich: akzeptiert z.B. "red", "RED" (Issue #16)
        if (preg_match('/xmp:Label\s*=\s*[\'"]([^\'"]+)[\'"]/', $xmp, $m)
            || preg_match('/<xmp:Label>([^<]+)<\/xmp:Label>/', $xmp, $m)) {
            $val   = trim($m[1]);
            $label = $this->resolveLabel($val);
        }

        return ['rating' => $rating, 'label' => $label];
    }

    /**
     * Normalisiert einen Label-String auf den kanonischen englischen Namen.
     * Akzeptiert case-insensitive englische Varianten (red, RED, Red).
     * Unbekannte Labels (z.B. lokalisierte LR-Labels) werden als null zurückgegeben.
     */
    private function resolveLabel(string $val): ?string
    {
        // Exakter Treffer
        if (isset(self::LABEL_MAP[$val])) {
            return $val;
        }

        // Case-insensitiver Fallback (z.B. "red" → "Red")
        foreach (array_keys(self::LABEL_MAP) as $canonical) {
            if (strcasecmp($val, $canonical) === 0) {
                return $canonical;
            }
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

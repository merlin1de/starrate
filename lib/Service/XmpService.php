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
                $this->logger->debug("StarRate: Sidecar aktualisiert: {$sidecarName}");
            } else {
                $folder->newFile($sidecarName, $xmpContent);
                $this->logger->debug("StarRate: Sidecar erstellt: {$sidecarName}");
            }
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Fehler beim Schreiben der Sidecar {$sidecarName}: " . $e->getMessage(),
                0,
                $e
            );
        }

        return $xmpContent;
    }

    /**
     * Schreibt eine Sidecar anhand eines absoluten lokalen Dateipfades.
     * Wird vom SyncService für lokale Lightroom-Ordner verwendet.
     *
     * @param  string      $localRawPath  Absoluter Pfad zur RAW-Datei (z. B. /foto/IMG_1234.cr3)
     * @param  int         $rating
     * @param  string|null $label
     * @return string                     Pfad der geschriebenen .xmp-Datei
     */
    public function writeSidecarLocal(string $localRawPath, int $rating, ?string $label): string
    {
        $dir         = dirname($localRawPath);
        $baseName    = pathinfo($localRawPath, PATHINFO_FILENAME);
        $sidecarPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.xmp';
        $xmpContent  = $this->buildXmpContent($rating, $label);

        if (file_put_contents($sidecarPath, $xmpContent) === false) {
            throw new \RuntimeException("Konnte Sidecar nicht schreiben: {$sidecarPath}");
        }

        $this->logger->debug("StarRate: Lokale Sidecar geschrieben: {$sidecarPath}");
        return $sidecarPath;
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
            $this->logger->warning("StarRate: Fehler beim Lesen der Sidecar {$sidecarName}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Liest eine .xmp-Sidecar von einem lokalen Pfad.
     *
     * @return array{rating: int, label: string|null, mtime: int}|null
     */
    public function readSidecarLocal(string $localRawPath): ?array
    {
        $dir         = dirname($localRawPath);
        $baseName    = pathinfo($localRawPath, PATHINFO_FILENAME);
        $sidecarPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.xmp';

        if (!file_exists($sidecarPath)) {
            return null;
        }

        $content = file_get_contents($sidecarPath);
        if ($content === false) {
            return null;
        }

        $parsed = $this->parseXmpContent($content);
        $parsed['mtime'] = (int) filemtime($sidecarPath);
        return $parsed;
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

    /**
     * Sucht in einem lokalen Verzeichnis alle Dateien mit dem gleichen Basis-Namen.
     *
     * @param  string   $localDir   Lokaler Ordnerpfad
     * @param  string   $baseName   Basis-Name ohne Erweiterung (z. B. "IMG_1234")
     * @return string[]             Absolute Pfade gefundener Dateien
     */
    public function findMatchingLocalFiles(string $localDir, string $baseName): array
    {
        if (!is_dir($localDir)) {
            return [];
        }

        $matches = [];
        $files   = scandir($localDir);
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (strcasecmp(pathinfo($file, PATHINFO_FILENAME), $baseName) === 0) {
                $matches[] = $localDir . DIRECTORY_SEPARATOR . $file;
            }
        }

        return $matches;
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
        $validLabels = self::LABEL_MAP;
        if (preg_match('/xmp:Label\s*=\s*[\'"]([^\'"]+)[\'"]/', $xmp, $m)) {
            $val = trim($m[1]);
            $label = isset($validLabels[$val]) ? $val : null;
        } elseif (preg_match('/<xmp:Label>([^<]+)<\/xmp:Label>/', $xmp, $m)) {
            $val = trim($m[1]);
            $label = isset($validLabels[$val]) ? $val : null;
        }

        return ['rating' => $rating, 'label' => $label];
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
            $this->logger->warning("StarRate: Sidecar löschen fehlgeschlagen: " . $e->getMessage());
        }
        return false;
    }
}

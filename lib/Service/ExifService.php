<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Liest und schreibt XMP/EXIF-Metadaten direkt in JPEG-Dateien.
 *
 * Verwendet einen eigenständigen XMP-Ansatz (kein pel/pel für XMP,
 * da PEL nur EXIF-IFD verwaltet). XMP wird als separates APP1-Segment
 * in den JPEG-Datenstrom eingebettet.
 *
 * Unterstützte Formate: JPEG / JPG
 * Schreibt: xmp:Rating (0–5), xmp:Label (Red/Yellow/Green/Blue/Purple)
 */
class ExifService
{
    // XMP-Namespace-Marker im JPEG
    private const XMP_MARKER     = "\xFF\xE1";
    private const XMP_NAMESPACE  = 'http://ns.adobe.com/xap/1.0/';
    private const XMP_MAGIC      = "http://ns.adobe.com/xap/1.0/\x00";

    // Lightroom-kompatible Label-Namen (kanonische Quelle: XmpService::LABEL_MAP)
    public const LABEL_MAP = XmpService::LABEL_MAP;

    // Maximale Dateigröße für direktes In-Memory-Bearbeiten (50 MB)
    private const MAX_MEMORY_SIZE = 52_428_800;

    public function __construct(
        private readonly XmpService      $xmpService,
        private readonly LoggerInterface $logger,
    ) {}

    // ─── Öffentliche API ──────────────────────────────────────────────────────

    /**
     * Schreibt Rating und/oder Label in die JPEG-Datei.
     *
     * @param  File         $file   Nextcloud File-Objekt
     * @param  int|null     $rating 0–5 (null = nicht ändern)
     * @param  string|null  $label  Red/Yellow/Green/Blue/Purple/'' (null = nicht ändern, '' = entfernen)
     * @throws \RuntimeException bei Fehler
     */
    public function writeMetadata(File $file, ?int $rating = null, ?string $label = null): void
    {
        $this->validateInputs($rating, $label);

        $content = $file->getContent();
        if (!$this->isJpeg($content)) {
            throw new \RuntimeException("Datei ist kein gültiges JPEG: " . $file->getName());
        }

        $existing = $this->readXmpFromContent($content);
        $merged   = $this->mergeXmpData($existing, $rating, $label);
        $newXmp   = $this->buildXmpPacket($merged['rating'], $merged['label']);
        $updated  = $this->embedXmpInJpeg($content, $newXmp);

        $file->putContent($updated);

        $this->logger->info(sprintf(
            'StarRate: XMP in %s geschrieben — Rating: %s, Label: %s',
            $file->getName(),
            $merged['rating'] ?? 'unverändert',
            $merged['label']  ?? 'unverändert'
        ));
    }

    /**
     * Liest Rating und Label aus einer JPEG-Datei.
     *
     * @return array{rating: int, label: string|null}
     */
    public function readMetadata(File $file): array
    {
        try {
            $content = $file->getContent();
            if (!$this->isJpeg($content)) {
                return ['rating' => 0, 'label' => null];
            }
            return $this->readXmpFromContent($content);
        } catch (\Exception $e) {
            $this->logger->warning("StarRate: Fehler beim Lesen von {$file->getName()}: " . $e->getMessage());
            return ['rating' => 0, 'label' => null];
        }
    }

    /**
     * Liest Metadaten aus rohem JPEG-Inhalt (für Tests und interne Nutzung).
     *
     * @return array{rating: int, label: string|null}
     */
    public function readMetadataFromContent(string $content): array
    {
        if (!$this->isJpeg($content)) {
            return ['rating' => 0, 'label' => null];
        }
        return $this->readXmpFromContent($content);
    }

    /**
     * Schreibt Metadaten in rohen JPEG-Inhalt und gibt den aktualisierten Inhalt zurück.
     * Hauptsächlich für Tests.
     */
    public function writeMetadataToContent(string $content, ?int $rating = null, ?string $label = null): string
    {
        $this->validateInputs($rating, $label);

        if (!$this->isJpeg($content)) {
            throw new \RuntimeException('Inhalt ist kein gültiges JPEG.');
        }

        $existing = $this->readXmpFromContent($content);
        $merged   = $this->mergeXmpData($existing, $rating, $label);
        $newXmp   = $this->buildXmpPacket($merged['rating'], $merged['label']);

        return $this->embedXmpInJpeg($content, $newXmp);
    }

    /**
     * Prüft ob eine Datei ein JPEG ist (anhand Magic Bytes, nicht Dateiendung).
     */
    public function isJpegFile(File $file): bool
    {
        try {
            $header = substr($file->getContent(), 0, 3);
            return $this->isJpeg($header);
        } catch (\Exception) {
            return false;
        }
    }

    // ─── XMP lesen ────────────────────────────────────────────────────────────

    /**
     * Extrahiert xmp:Rating und xmp:Label aus dem JPEG-Inhalt.
     *
     * @return array{rating: int, label: string|null}
     */
    private function readXmpFromContent(string $content): array
    {
        $xmpBlock = $this->extractXmpBlock($content);
        if ($xmpBlock === null) {
            return ['rating' => 0, 'label' => null];
        }

        return $this->parseXmp($xmpBlock);
    }

    /**
     * Sucht das XMP-APP1-Segment im JPEG-Datenstrom.
     */
    private function extractXmpBlock(string $content): ?string
    {
        $len    = strlen($content);
        $offset = 2; // SOI überspringen

        while ($offset < $len - 4) {
            if ($content[$offset] !== "\xFF") {
                break;
            }

            $marker     = $content[$offset + 1];
            $segmentLen = (ord($content[$offset + 2]) << 8) | ord($content[$offset + 3]);

            // APP1 = 0xE1
            if ($marker === "\xE1" && $segmentLen > strlen(self::XMP_MAGIC)) {
                $payload = substr($content, $offset + 4, $segmentLen - 2);
                if (str_starts_with($payload, self::XMP_MAGIC)) {
                    return substr($payload, strlen(self::XMP_MAGIC));
                }
            }

            // SOS (Start of Scan) → Bilddaten beginnen, kein weiteres Segment
            if ($marker === "\xDA") {
                break;
            }

            $offset += 2 + $segmentLen;
        }

        return null;
    }

    /**
     * Parst einen XMP-String und extrahiert Rating und Label.
     * Delegiert an XmpService::parseXmpContent().
     *
     * @return array{rating: int, label: string|null}
     */
    private function parseXmp(string $xmp): array
    {
        return $this->xmpService->parseXmpContent($xmp);
    }

    // ─── XMP schreiben ────────────────────────────────────────────────────────

    /**
     * Merged vorhandene Daten mit neuen Werten (null = bestehenden Wert beibehalten).
     *
     * @return array{rating: int, label: string|null}
     */
    private function mergeXmpData(array $existing, ?int $rating, ?string $label): array
    {
        return [
            'rating' => $rating !== null ? $rating : $existing['rating'],
            'label'  => $label  !== null ? ($label === '' ? null : $label) : $existing['label'],
        ];
    }

    /**
     * Baut ein vollständiges XMP-Paket als String.
     * Delegiert an XmpService::buildXmpContent().
     */
    private function buildXmpPacket(int $rating, ?string $label): string
    {
        return $this->xmpService->buildXmpContent($rating, $label);
    }

    /**
     * Bettet das XMP-Paket in den JPEG-Datenstrom ein.
     * Ersetzt ein vorhandenes XMP-Segment oder fügt eines nach SOI ein.
     */
    private function embedXmpInJpeg(string $content, string $xmpPacket): string
    {
        $xmpPayload   = self::XMP_MAGIC . $xmpPacket;
        $segmentLen   = strlen($xmpPayload) + 2; // +2 für Längenfeld selbst

        if ($segmentLen > 0xFFFF) {
            throw new \RuntimeException('XMP-Paket ist zu groß für ein einzelnes JPEG-Segment (max 65533 Bytes).');
        }

        $newSegment = self::XMP_MARKER
            . chr(($segmentLen >> 8) & 0xFF)
            . chr($segmentLen & 0xFF)
            . $xmpPayload;

        // Vorhandenes XMP-Segment suchen und ersetzen
        $result = $this->replaceExistingXmpSegment($content, $newSegment);
        if ($result !== null) {
            return $result;
        }

        // Kein vorhandenes XMP → nach SOI (2 Bytes) einfügen
        return substr($content, 0, 2) . $newSegment . substr($content, 2);
    }

    /**
     * Sucht und ersetzt ein vorhandenes XMP-APP1-Segment.
     * Gibt null zurück wenn kein XMP-Segment gefunden.
     */
    private function replaceExistingXmpSegment(string $content, string $newSegment): ?string
    {
        $len    = strlen($content);
        $offset = 2;

        while ($offset < $len - 4) {
            if ($content[$offset] !== "\xFF") {
                break;
            }

            $marker     = $content[$offset + 1];
            $segmentLen = (ord($content[$offset + 2]) << 8) | ord($content[$offset + 3]);

            if ($marker === "\xE1" && $segmentLen > strlen(self::XMP_MAGIC)) {
                $payload = substr($content, $offset + 4, $segmentLen - 2);
                if (str_starts_with($payload, self::XMP_MAGIC)) {
                    // Dieses Segment ersetzen
                    return substr($content, 0, $offset)
                        . $newSegment
                        . substr($content, $offset + 2 + $segmentLen);
                }
            }

            if ($marker === "\xDA") {
                break;
            }

            $offset += 2 + $segmentLen;
        }

        return null;
    }

    // ─── Hilfsmethoden ───────────────────────────────────────────────────────

    private function isJpeg(string $content): bool
    {
        return strlen($content) >= 2
            && $content[0] === "\xFF"
            && $content[1] === "\xD8";
    }

    private function validateInputs(?int $rating, ?string $label): void
    {
        if ($rating !== null && ($rating < 0 || $rating > 5)) {
            throw new \InvalidArgumentException("Rating muss zwischen 0 und 5 liegen, erhalten: {$rating}");
        }

        if ($label !== null && $label !== '' && !isset(self::LABEL_MAP[$label])) {
            throw new \InvalidArgumentException(
                "Ungültiges Label: {$label}. Erlaubt: " . implode(', ', array_keys(self::LABEL_MAP))
            );
        }
    }
}

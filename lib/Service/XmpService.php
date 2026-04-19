<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

/**
 * Baut und parst XMP-Metadaten-Strings für die JPEG-Einbettung durch ExifService.
 *
 * Das erzeugte XMP ist Lightroom Classic / Adobe Camera Raw kompatibel.
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
}

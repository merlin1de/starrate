<?php
/**
 * StarRate – Test Fixture Generator
 *
 * Generates binary JPEG and CR3 fixture files for unit tests.
 * Run once: php tests/fixtures/generate_fixtures.php
 */

declare(strict_types=1);

$dir = __DIR__;

// ── Minimal 1×1 JPEG builder ──────────────────────────────────────────────

/**
 * Build a minimal valid 1×1 greyscale JPEG without any APP1/XMP segment.
 */
function buildMinimalJpeg(): string
{
    // SOI
    $soi = "\xFF\xD8";
    // APP0 JFIF
    $app0 = "\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
    // DQT (simplified luma quantization table, all 1s for simplicity)
    $dqt  = "\xFF\xDB\x00\x43\x00";
    $dqt .= str_repeat("\x01", 64);
    // SOF0: 1×1, 1 component, baseline DCT
    $sof0 = "\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00";
    // DHT luma DC (minimal)
    $dht  = "\xFF\xC4\x00\x1F\x00"
          . "\x00\x01\x05\x01\x01\x01\x01\x01\x01\x00\x00\x00\x00\x00\x00\x00"
          . "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B";
    // SOS + entropy-coded data (single MCU for a 1×1 white block)
    $sos  = "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00\xF8\x00";
    // EOI
    $eoi  = "\xFF\xD9";

    return $soi . $app0 . $dqt . $sof0 . $dht . $sos . $eoi;
}

/**
 * Build a minimal JPEG with an XMP APP1 segment containing the given rating+label.
 */
function buildJpegWithXmp(int $rating, string $label = ''): string
{
    $soi  = "\xFF\xD8";
    $app0 = "\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    // XMP packet
    $xmpPayload = '<?xpacket begin="﻿" id="W5M0MpCehiHzreSzNTczkc9d"?>'
        . '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
        . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
        . '<rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/"'
        . ' xmp:Rating="' . $rating . '"'
        . ($label ? ' xmp:Label="' . htmlspecialchars($label) . '"' : '')
        . '/>'
        . '</rdf:RDF>'
        . '</x:xmpmeta>'
        . '<?xpacket end="w"?>';

    $magic   = "http://ns.adobe.com/xap/1.0/\x00";
    $segment = $magic . $xmpPayload;
    $len     = strlen($segment) + 2; // +2 for the length field itself
    $app1    = "\xFF\xE1" . pack('n', $len) . $segment;

    $dqt  = "\xFF\xDB\x00\x43\x00" . str_repeat("\x01", 64);
    $sof0 = "\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00";
    $dht  = "\xFF\xC4\x00\x1F\x00"
          . "\x00\x01\x05\x01\x01\x01\x01\x01\x01\x00\x00\x00\x00\x00\x00\x00"
          . "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B";
    $sos  = "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00\xF8\x00";
    $eoi  = "\xFF\xD9";

    return $soi . $app0 . $app1 . $dqt . $sof0 . $dht . $sos . $eoi;
}

/**
 * Build a minimal CR3 dummy (ISOBMFF container, 'crx ' brand).
 * Not a valid decodable image — only used to test MIME/extension detection.
 */
function buildDummyCr3(string $filename): string
{
    // ftyp box: size(4) + 'ftyp'(4) + major_brand 'crx '(4) + minor_version(4) + compatible_brands
    $ftypBox = pack('N', 24) . 'ftyp' . 'crx ' . pack('N', 0) . 'crx isom';
    // mdat box (empty image data placeholder)
    $mdatBox = pack('N', 8) . 'mdat';
    return $ftypBox . $mdatBox;
}

// ── Generate files ─────────────────────────────────────────────────────────

$files = [
    // No XMP at all
    'test_image_no_exif.jpg'     => buildMinimalJpeg(),
    // Rating 4, label Red
    'test_image_with_rating.jpg' => buildJpegWithXmp(4, 'Red'),
    // Rating 0 (cleared)
    'test_image_rated_zero.jpg'  => buildJpegWithXmp(0),
    // Rating 5, label Purple
    'test_image_rated_5.jpg'     => buildJpegWithXmp(5, 'Purple'),
    // Large JPEG: no_exif padded with 1 MB of comment data
    'test_image_large.jpg'       => (function () {
        $base    = buildMinimalJpeg();
        // Insert a COM segment with 64 KB of padding right after SOI
        $padding = str_repeat('X', 65530);
        $com     = "\xFF\xFE" . pack('n', 65532) . $padding;
        return substr($base, 0, 2) . $com . substr($base, 2);
    })(),
    // CR3 dummies
    'dummy.cr3'                  => buildDummyCr3('dummy.cr3'),
    'IMG_0001.cr3'               => buildDummyCr3('IMG_0001.cr3'),
    'IMG_0002.cr3'               => buildDummyCr3('IMG_0002.cr3'),
];

foreach ($files as $name => $content) {
    $path = $dir . '/' . $name;
    file_put_contents($path, $content);
    echo "Created: $name (" . strlen($content) . " bytes)\n";
}

echo "\nAll fixtures generated successfully.\n";

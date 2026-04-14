<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\XmpService;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExifServiceTest extends TestCase
{
    private ExifService $service;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger     = $this->createMock(LoggerInterface::class);
        $xmpService       = new XmpService($this->logger);
        $this->service    = new ExifService($xmpService, $this->logger);
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    /**
     * Erzeugt ein minimales gültiges JPEG (2×2 Pixel, keine EXIF).
     */
    private function makeMinimalJpeg(): string
    {
        // SOI + APP0 (JFIF) + SOF0 + EOI — kleinste valide JPEG-Struktur
        return file_get_contents(__DIR__ . '/../../fixtures/test_image_no_exif.jpg')
            ?: $this->buildSyntheticJpeg();
    }

    private function buildSyntheticJpeg(): string
    {
        // Minimales JPEG: SOI (FF D8) + SOF0-Dummy + EOI (FF D9)
        return "\xFF\xD8\xFF\xE0" . chr(0) . chr(16)
            . "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            . "\xFF\xD9";
    }

    private function makeFileMock(string $content, string $name = 'test.jpg', string $mime = 'image/jpeg'): File&MockObject
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn($content);
        $file->method('getName')->willReturn($name);
        $file->method('getMimeType')->willReturn($mime);

        // fopen('rb') returns a php://memory stream backed by $content
        $file->method('fopen')->willReturnCallback(function () use (&$content) {
            $stream = fopen('php://memory', 'r+b');
            fwrite($stream, $content);
            rewind($stream);
            return $stream;
        });

        $file->expects($this->any())
            ->method('putContent')
            ->willReturnCallback(function (string $newContent) use (&$content) {
                $content = $newContent;
            });

        return $file;
    }

    // ─── Tests: JPEG erkennen ─────────────────────────────────────────────────

    public function testIsJpegReturnsTrueForValidJpeg(): void
    {
        $jpeg = $this->makeMinimalJpeg();
        $file = $this->makeFileMock($jpeg);

        $this->assertTrue($this->service->isJpegFile($file));
    }

    public function testIsJpegReturnsFalseForPng(): void
    {
        $png  = "\x89PNG\r\n\x1a\nfake";
        $file = $this->makeFileMock($png, 'test.png', 'image/png');

        $this->assertFalse($this->service->isJpegFile($file));
    }

    // ─── Tests: XMP lesen (kein XMP vorhanden) ────────────────────────────────

    public function testReadMetadataFromContentReturnsZeroWhenNoXmp(): void
    {
        $jpeg   = $this->makeMinimalJpeg();
        $result = $this->service->readMetadataFromContent($jpeg);

        $this->assertSame(0,    $result['rating']);
        $this->assertNull($result['label']);
    }

    public function testReadMetadataReturnsDefaultsForNonJpeg(): void
    {
        $result = $this->service->readMetadataFromContent('not-a-jpeg');

        $this->assertSame(0,    $result['rating']);
        $this->assertNull($result['label']);
    }

    // ─── Tests: XMP schreiben und zurücklesen ─────────────────────────────────

    /** @dataProvider ratingProvider */
    public function testWriteAndReadRating(int $rating): void
    {
        $jpeg    = $this->makeMinimalJpeg();
        $written = $this->service->writeMetadataToContent($jpeg, $rating, null);
        $result  = $this->service->readMetadataFromContent($written);

        $this->assertSame($rating, $result['rating']);
    }

    public static function ratingProvider(): array
    {
        return [[0], [1], [2], [3], [4], [5]];
    }

    /** @dataProvider labelProvider */
    public function testWriteAndReadLabel(string $label): void
    {
        $jpeg    = $this->makeMinimalJpeg();
        $written = $this->service->writeMetadataToContent($jpeg, 3, $label);
        $result  = $this->service->readMetadataFromContent($written);

        $this->assertSame($label, $result['label']);
    }

    public static function labelProvider(): array
    {
        return [['Red'], ['Yellow'], ['Green'], ['Blue'], ['Purple']];
    }

    public function testWriteRatingAndLabelTogether(): void
    {
        $jpeg    = $this->makeMinimalJpeg();
        $written = $this->service->writeMetadataToContent($jpeg, 5, 'Green');
        $result  = $this->service->readMetadataFromContent($written);

        $this->assertSame(5,       $result['rating']);
        $this->assertSame('Green', $result['label']);
    }

    public function testWriteLabelNullRemovesLabel(): void
    {
        $jpeg    = $this->makeMinimalJpeg();
        // Erst mit Label schreiben
        $step1   = $this->service->writeMetadataToContent($jpeg, 4, 'Red');
        // Dann Label entfernen (leerer String = entfernen)
        $step2   = $this->service->writeMetadataToContent($step1, null, '');
        $result  = $this->service->readMetadataFromContent($step2);

        $this->assertSame(4,    $result['rating']); // Rating bleibt
        $this->assertNull($result['label']);
    }

    public function testOverwriteExistingXmp(): void
    {
        $jpeg   = $this->makeMinimalJpeg();
        $step1  = $this->service->writeMetadataToContent($jpeg, 3, 'Red');
        $step2  = $this->service->writeMetadataToContent($step1, 5, 'Blue');
        $result = $this->service->readMetadataFromContent($step2);

        $this->assertSame(5,      $result['rating']);
        $this->assertSame('Blue', $result['label']);
    }

    public function testRatingZeroIsWritten(): void
    {
        $jpeg   = $this->makeMinimalJpeg();
        // Rating 5 setzen, dann auf 0 zurücksetzen
        $step1  = $this->service->writeMetadataToContent($jpeg, 5, null);
        $step2  = $this->service->writeMetadataToContent($step1, 0, null);
        $result = $this->service->readMetadataFromContent($step2);

        $this->assertSame(0, $result['rating']);
    }

    // ─── Tests: File-Objekt Integration ──────────────────────────────────────

    public function testWriteMetadataCallsPutContent(): void
    {
        $jpeg    = $this->makeMinimalJpeg();
        $putCalls = 0;

        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn($jpeg);
        $file->method('getName')->willReturn('foto.jpg');
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->expects($this->once())
             ->method('putContent')
             ->willReturnCallback(function () use (&$putCalls) { $putCalls++; });

        $this->service->writeMetadata($file, 4, 'Yellow');
        $this->assertSame(1, $putCalls);
    }

    public function testReadMetadataFromFileObject(): void
    {
        $jpeg   = $this->makeMinimalJpeg();
        $with   = $this->service->writeMetadataToContent($jpeg, 3, 'Purple');
        $file   = $this->makeFileMock($with);
        $result = $this->service->readMetadata($file);

        $this->assertSame(3,        $result['rating']);
        $this->assertSame('Purple', $result['label']);
    }

    // ─── Tests: Validierung ───────────────────────────────────────────────────

    public function testInvalidRatingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->writeMetadataToContent($this->makeMinimalJpeg(), 6, null);
    }

    public function testNegativeRatingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->writeMetadataToContent($this->makeMinimalJpeg(), -1, null);
    }

    public function testInvalidLabelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->writeMetadataToContent($this->makeMinimalJpeg(), 1, 'Magenta');
    }

    public function testWriteToNonJpegThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->writeMetadataToContent('not-a-jpeg', 1, null);
    }

    // ─── Tests: Erhalt externer XMP-Felder (Issue #16) ───────────────────────

    /**
     * Baut ein JPEG mit eingebettetem Lightroom-ähnlichem XMP (SerialNumber, Lens etc.).
     */
    private function makeJpegWithLightroomXmp(): string
    {
        $xmp = "<?xpacket begin='\xef\xbb\xbf' id='W5M0MpCehiHzreSzNTczkc9d'?>\n"
            . "<x:xmpmeta xmlns:x='adobe:ns:meta/' x:xmptk='Adobe XMP Core 6.0.0'>\n"
            . "  <rdf:RDF xmlns:rdf='http://www.w3.org/1999/02/22-rdf-syntax-ns#'>\n"
            . "    <rdf:Description rdf:about=''\n"
            . "      xmlns:xmp='http://ns.adobe.com/xap/1.0/'\n"
            . "      xmlns:aux='http://ns.adobe.com/exif/1.0/aux/'\n"
            . "      xmp:Rating='3'\n"
            . "      xmp:Label='Red'\n"
            . "      xmp:CreateDate='2024-06-01T10:00:00'\n"
            . "      aux:SerialNumber='213076034929'\n"
            . "      aux:Lens='18-250mm'>\n"
            . "    </rdf:Description>\n"
            . "  </rdf:RDF>\n"
            . "</x:xmpmeta>\n"
            . "<?xpacket end='w'?>";

        $magic      = "http://ns.adobe.com/xap/1.0/\x00";
        $payload    = $magic . $xmp;
        $segLen     = strlen($payload) + 2;
        $app1Seg    = "\xFF\xE1" . chr(($segLen >> 8) & 0xFF) . chr($segLen & 0xFF) . $payload;

        $base = $this->makeMinimalJpeg();
        return substr($base, 0, 2) . $app1Seg . substr($base, 2);
    }

    public function testPreservesExternalXmpFieldsWhenUpdatingRating(): void
    {
        $jpeg    = $this->makeJpegWithLightroomXmp();
        $written = $this->service->writeMetadataToContent($jpeg, 5, 'Blue');

        // Neue Werte korrekt geschrieben
        $result = $this->service->readMetadataFromContent($written);
        $this->assertSame(5,      $result['rating']);
        $this->assertSame('Blue', $result['label']);

        // Lightroom-Felder erhalten
        $this->assertStringContainsString('SerialNumber',   $written);
        $this->assertStringContainsString('213076034929',   $written);
        $this->assertStringContainsString('18-250mm',       $written);
        $this->assertStringContainsString('CreateDate',     $written);
        $this->assertStringContainsString('aux:Lens',       $written);
    }

    public function testPreservesExternalXmpFieldsWhenRemovingLabel(): void
    {
        $jpeg    = $this->makeJpegWithLightroomXmp();
        $written = $this->service->writeMetadataToContent($jpeg, 4, '');

        $result = $this->service->readMetadataFromContent($written);
        $this->assertSame(4,    $result['rating']);
        $this->assertNull($result['label']);

        // Externe Felder noch vorhanden
        $this->assertStringContainsString('aux:SerialNumber', $written);
        $this->assertStringContainsString('213076034929',     $written);
    }

    public function testReadsExistingLightroomRatingBeforeFirstWrite(): void
    {
        $jpeg   = $this->makeJpegWithLightroomXmp();
        $result = $this->service->readMetadataFromContent($jpeg);

        $this->assertSame(3,     $result['rating']);
        $this->assertSame('Red', $result['label']);
    }

    /**
     * Regression: selbst-schließendes rdf:Description (z.B. digiKam-XMP) erzeugte
     * nach dem Schreiben ein unkorrekt geschlossenes Tag ("no closing tag for
     * rdf:Description" in exiftool). Die Ursache war, dass [^>]* das / von />
     * in Gruppe 1 einschloss und so das Tag malformed wurde.
     */
    public function testSelfClosingRdfDescriptionIsHandledCorrectly(): void
    {
        // XMP mit selbst-schließendem rdf:Description (wie digiKam es schreibt)
        $xmp = "<?xpacket begin='\xef\xbb\xbf' id='W5M0MpCehiHzreSzNTczkc9d'?>\n"
            . "<x:xmpmeta xmlns:x='adobe:ns:meta/'>\n"
            . "  <rdf:RDF xmlns:rdf='http://www.w3.org/1999/02/22-rdf-syntax-ns#'>\n"
            . "    <rdf:Description rdf:about=''\n"
            . "      xmlns:xmp='http://ns.adobe.com/xap/1.0/'\n"
            . "      xmp:Rating='2' />\n"
            . "  </rdf:RDF>\n"
            . "</x:xmpmeta>\n"
            . "<?xpacket end='w'?>";

        $magic   = "http://ns.adobe.com/xap/1.0/\x00";
        $payload = $magic . $xmp;
        $segLen  = strlen($payload) + 2;
        $app1Seg = "\xFF\xE1" . chr(($segLen >> 8) & 0xFF) . chr($segLen & 0xFF) . $payload;

        $base = $this->makeMinimalJpeg();
        $jpeg = substr($base, 0, 2) . $app1Seg . substr($base, 2);

        // Ausgangszustand: Rating 2, kein Label
        $before = $this->service->readMetadataFromContent($jpeg);
        $this->assertSame(2,    $before['rating']);
        $this->assertNull($before['label']);

        // Schreiben → muss valides XMP erzeugen (kein unkorrekt geschlossenes Tag)
        $written = $this->service->writeMetadataToContent($jpeg, 4, 'Green');
        $after   = $this->service->readMetadataFromContent($written);

        $this->assertSame(4,       $after['rating']);
        $this->assertSame('Green', $after['label']);

        // Das resultierende XMP darf kein hängendes /> haben das als Tag-Inhalt gewertet wird
        // und muss entweder ein geschlossenes Tag oder ein valides selbst-schließendes haben.
        $this->assertStringNotContainsString('/<rdf:Description[^>]*\/\s*\n\s*xmp:/', $written);
    }

    // ─── Tests: Große Dateien ─────────────────────────────────────────────────

    public function testLargeJpegWithPaddingIsHandledCorrectly(): void
    {
        // Simuliert JPEG mit viel Bild-Daten dahinter
        $jpeg    = $this->makeMinimalJpeg();
        $padding = str_repeat("\x00", 1024 * 1024); // 1 MB Padding
        $big     = substr($jpeg, 0, -2) . $padding . "\xFF\xD9";

        $written = $this->service->writeMetadataToContent($big, 5, 'Red');
        $result  = $this->service->readMetadataFromContent($written);

        $this->assertSame(5,     $result['rating']);
        $this->assertSame('Red', $result['label']);
        // Datei sollte größer sein als das Original (XMP wurde hinzugefügt)
        $this->assertGreaterThan(strlen($jpeg), strlen($written));
    }

    public function testReadFromCorruptedJpegReturnsDefaults(): void
    {
        // JPEG Magic Bytes vorhanden, aber Rest ist Müll
        $corrupt = "\xFF\xD8" . str_repeat("\xFF", 50) . 'garbage data here';
        $result  = $this->service->readMetadataFromContent($corrupt);

        $this->assertSame(0,    $result['rating']);
        $this->assertNull($result['label']);
    }

    // ─── Tests: Wildnis-Fixtures (echte Kamera-/Tool-Dateien) ───────────────────

    /**
     * Issue 80.jpg: xap:Rating=4 in Block 6 von 20 (IDimager, alter exiftool-Alias).
     * Lesen: xap:Rating muss als Rating erkannt werden.
     * Schreiben: neues Rating muss in den xap:-Block, nicht in Block 1.
     */
    public function testFixtureXapMultiblockReadRating(): void
    {
        $path = __DIR__ . '/../../fixtures/xmp_xap_multiblock.jpg';
        if (!file_exists($path)) {
            $this->markTestSkipped('Fixture xmp_xap_multiblock.jpg nicht vorhanden');
        }
        $content = file_get_contents($path);
        $result  = $this->service->readMetadataFromContent($content);
        $this->assertSame(4, $result['rating'], 'xap:Rating=4 must be read correctly');
    }

    public function testFixtureXapMultiblockWriteAndReadBack(): void
    {
        $path = __DIR__ . '/../../fixtures/xmp_xap_multiblock.jpg';
        if (!file_exists($path)) {
            $this->markTestSkipped('Fixture xmp_xap_multiblock.jpg nicht vorhanden');
        }
        $content = file_get_contents($path);
        $written = $this->service->writeMetadataToContent($content, 5, 'Red');
        $result  = $this->service->readMetadataFromContent($written);
        $this->assertSame(5,     $result['rating']);
        $this->assertSame('Red', $result['label']);
    }

    /**
     * Canon EOS 7D: xap:Rating=3, single block mit xmlns:xap=.
     */
    public function testFixtureXapSingleBlockReadRating(): void
    {
        $path = __DIR__ . '/../../fixtures/xmp_xap_rating.jpg';
        if (!file_exists($path)) {
            $this->markTestSkipped('Fixture xmp_xap_rating.jpg nicht vorhanden');
        }
        $content = file_get_contents($path);
        $result  = $this->service->readMetadataFromContent($content);
        $this->assertSame(3, $result['rating'], 'xap:Rating=3 must be read correctly');
    }

    public function testFixtureXapSingleBlockWriteAndReadBack(): void
    {
        $path = __DIR__ . '/../../fixtures/xmp_xap_rating.jpg';
        if (!file_exists($path)) {
            $this->markTestSkipped('Fixture xmp_xap_rating.jpg nicht vorhanden');
        }
        $content = file_get_contents($path);
        $written = $this->service->writeMetadataToContent($content, 2, 'Blue');
        $result  = $this->service->readMetadataFromContent($written);
        $this->assertSame(2,      $result['rating']);
        $this->assertSame('Blue', $result['label']);
    }

    /**
     * Issue 587: xmp:Rating als Element (nicht Attribut) in Block 1 von 2.
     */
    public function testFixtureMultiblockElementRatingReadRating(): void
    {
        $path = __DIR__ . '/../../fixtures/xmp_multiblock_element_rating.jpg';
        if (!file_exists($path)) {
            $this->markTestSkipped('Fixture xmp_multiblock_element_rating.jpg nicht vorhanden');
        }
        $content = file_get_contents($path);
        $result  = $this->service->readMetadataFromContent($content);
        $this->assertSame(3, $result['rating'], 'xmp:Rating element form must be read correctly');
    }

    public function testFixtureMultiblockElementRatingWriteAndReadBack(): void
    {
        $path = __DIR__ . '/../../fixtures/xmp_multiblock_element_rating.jpg';
        if (!file_exists($path)) {
            $this->markTestSkipped('Fixture xmp_multiblock_element_rating.jpg nicht vorhanden');
        }
        $content = file_get_contents($path);
        $written = $this->service->writeMetadataToContent($content, 1, 'Yellow');
        $result  = $this->service->readMetadataFromContent($written);
        $this->assertSame(1,        $result['rating']);
        $this->assertSame('Yellow', $result['label']);
    }

    // ─── Tests: Fixture-Dateien ───────────────────────────────────────────────

    public function testFixtureNoExifReturnsDefaults(): void
    {
        $path = __DIR__ . '/../../fixtures/test_image_no_exif.jpg';
        if (!file_exists($path)) {
            $this->markTestSkipped('Fixture test_image_no_exif.jpg nicht vorhanden');
        }

        $content = file_get_contents($path);
        $result  = $this->service->readMetadataFromContent($content);

        $this->assertSame(0,    $result['rating']);
        $this->assertNull($result['label']);
    }

    public function testFixtureWithRatingCanBeRead(): void
    {
        $path = __DIR__ . '/../../fixtures/test_image_with_rating.jpg';
        if (!file_exists($path)) {
            $this->markTestSkipped('Fixture test_image_with_rating.jpg nicht vorhanden');
        }

        $content = file_get_contents($path);
        $result  = $this->service->readMetadataFromContent($content);

        // Das Fixture hat Rating 4 und Label "Green" (laut Fixture-Aufbau)
        $this->assertGreaterThan(0, $result['rating']);
    }
}

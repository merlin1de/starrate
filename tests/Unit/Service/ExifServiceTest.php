<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\ExifService;
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
        $this->logger  = $this->createMock(LoggerInterface::class);
        $this->service = new ExifService($this->logger);
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

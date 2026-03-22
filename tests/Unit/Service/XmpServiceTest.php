<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\XmpService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class XmpServiceTest extends TestCase
{
    private XmpService $service;

    protected function setUp(): void
    {
        $this->service = new XmpService($this->createMock(LoggerInterface::class));
    }

    // ─── Tests: XMP aufbauen ──────────────────────────────────────────────────

    public function testBuildXmpContentContainsRating(): void
    {
        $xmp = $this->service->buildXmpContent(4, null);

        $this->assertStringContainsString('xmp:Rating="4"', $xmp);
        $this->assertStringContainsString('<?xpacket', $xmp);
        $this->assertStringContainsString('<?xpacket end=', $xmp);
    }

    public function testBuildXmpContentContainsLabel(): void
    {
        $xmp = $this->service->buildXmpContent(3, 'Green');

        $this->assertStringContainsString('xmp:Rating="3"', $xmp);
        $this->assertStringContainsString('xmp:Label="Green"', $xmp);
    }

    public function testBuildXmpContentWithoutLabelHasNoLabelAttribute(): void
    {
        $xmp = $this->service->buildXmpContent(2, null);

        $this->assertStringNotContainsString('xmp:Label', $xmp);
    }

    public function testBuildXmpContentZeroRating(): void
    {
        $xmp = $this->service->buildXmpContent(0, null);

        $this->assertStringContainsString('xmp:Rating="0"', $xmp);
    }

    /** @dataProvider allColorsProvider */
    public function testBuildXmpContentAllColors(string $color): void
    {
        $xmp = $this->service->buildXmpContent(1, $color);

        $this->assertStringContainsString("xmp:Label=\"{$color}\"", $xmp);
    }

    public static function allColorsProvider(): array
    {
        return [['Red'], ['Yellow'], ['Green'], ['Blue'], ['Purple']];
    }

    // ─── Tests: XMP parsen ────────────────────────────────────────────────────

    public function testParseXmpContentAttributeForm(): void
    {
        $xmp = <<<XMP
<?xpacket begin='' id='W5M0MpCehiHzreSzNTczkc9d'?>
<x:xmpmeta xmlns:x='adobe:ns:meta/'>
  <rdf:RDF xmlns:rdf='http://www.w3.org/1999/02/22-rdf-syntax-ns#'>
    <rdf:Description rdf:about=''
      xmlns:xmp='http://ns.adobe.com/xap/1.0/'
      xmp:Rating='5'
      xmp:Label='Red'>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
<?xpacket end='w'?>
XMP;
        $result = $this->service->parseXmpContent($xmp);

        $this->assertSame(5,     $result['rating']);
        $this->assertSame('Red', $result['label']);
    }

    public function testParseXmpContentElementForm(): void
    {
        $xmp = '<xmp:Rating>3</xmp:Rating><xmp:Label>Blue</xmp:Label>';
        $result = $this->service->parseXmpContent($xmp);

        $this->assertSame(3,      $result['rating']);
        $this->assertSame('Blue', $result['label']);
    }

    public function testParseXmpContentEmptyReturnsDefaults(): void
    {
        $result = $this->service->parseXmpContent('<x:xmpmeta/>');

        $this->assertSame(0,    $result['rating']);
        $this->assertNull($result['label']);
    }

    public function testParseXmpContentInvalidRatingClamped(): void
    {
        $xmp    = "xmp:Rating='9'";
        $result = $this->service->parseXmpContent($xmp);

        $this->assertSame(0, $result['rating']); // ungültig → 0
    }

    public function testParseXmpContentInvalidLabelIgnored(): void
    {
        $xmp    = "xmp:Rating='2' xmp:Label='Magenta'";
        $result = $this->service->parseXmpContent($xmp);

        $this->assertSame(2,    $result['rating']);
        $this->assertNull($result['label']); // unbekannte Farbe → null
    }

    // ─── Tests: Round-Trip (bauen + parsen) ───────────────────────────────────

    /** @dataProvider roundTripProvider */
    public function testBuildAndParseRoundTrip(int $rating, ?string $label): void
    {
        $xmp    = $this->service->buildXmpContent($rating, $label);
        $result = $this->service->parseXmpContent($xmp);

        $this->assertSame($rating, $result['rating']);
        $this->assertSame($label,  $result['label']);
    }

    public static function roundTripProvider(): array
    {
        return [
            [0, null],
            [1, null],
            [5, 'Red'],
            [3, 'Purple'],
            [4, 'Green'],
        ];
    }

    // ─── Tests: Dateiname-Matching ────────────────────────────────────────────

    public function testGetBaseNameStripsExtension(): void
    {
        $this->assertSame('IMG_1234', $this->service->getBaseName('IMG_1234.jpg'));
        $this->assertSame('IMG_1234', $this->service->getBaseName('IMG_1234.JPG'));
        $this->assertSame('IMG_1234', $this->service->getBaseName('IMG_1234.cr3'));
        $this->assertSame('IMG_1234', $this->service->getBaseName('IMG_1234.CR3'));
        $this->assertSame('_DSC0042', $this->service->getBaseName('_DSC0042.ARW'));
    }

    public function testIsSameBaseMatchesCase(): void
    {
        $this->assertTrue($this->service->isSameBase('IMG_1234.jpg',  'IMG_1234.cr3'));
        $this->assertTrue($this->service->isSameBase('IMG_1234.JPG',  'IMG_1234.CR3'));
        $this->assertTrue($this->service->isSameBase('IMG_1234.jpeg', 'IMG_1234.xmp'));
    }

    public function testIsSameBaseReturnsFalseForDifferentNames(): void
    {
        $this->assertFalse($this->service->isSameBase('IMG_1234.jpg', 'IMG_5678.cr3'));
        $this->assertFalse($this->service->isSameBase('DSC_0001.jpg', 'DSC_0002.jpg'));
    }

    public function testIsSameBaseCaseInsensitive(): void
    {
        $this->assertTrue($this->service->isSameBase('img_1234.jpg', 'IMG_1234.CR3'));
    }

    public function testIsRawFileDetectsRawFormats(): void
    {
        $this->assertTrue($this->service->isRawFile('IMG_1234.cr3'));
        $this->assertTrue($this->service->isRawFile('IMG_1234.CR3'));
        $this->assertTrue($this->service->isRawFile('IMG_1234.nef'));
        $this->assertTrue($this->service->isRawFile('IMG_1234.arw'));
        $this->assertTrue($this->service->isRawFile('IMG_1234.dng'));
    }

    public function testIsRawFileReturnsFalseForJpeg(): void
    {
        $this->assertFalse($this->service->isRawFile('IMG_1234.jpg'));
        $this->assertFalse($this->service->isRawFile('IMG_1234.jpeg'));
        $this->assertFalse($this->service->isRawFile('IMG_1234.png'));
    }

    public function testIsJpegFileDetectsJpeg(): void
    {
        $this->assertTrue($this->service->isJpegFile('test.jpg'));
        $this->assertTrue($this->service->isJpegFile('test.jpeg'));
        $this->assertTrue($this->service->isJpegFile('test.JPG'));
        $this->assertTrue($this->service->isJpegFile('test.JPEG'));
    }

    // ─── Tests: Lokale Sidecar schreiben/lesen ────────────────────────────────

    public function testWriteAndReadSidecarLocal(): void
    {
        $dir    = sys_get_temp_dir();
        $raw    = $dir . DIRECTORY_SEPARATOR . 'starrate_test_IMG_9999.cr3';
        $sidecar = $dir . DIRECTORY_SEPARATOR . 'starrate_test_IMG_9999.xmp';

        // Aufräumen
        @unlink($raw);
        @unlink($sidecar);

        // Dummy-RAW erstellen
        file_put_contents($raw, 'CANON_CR3_DUMMY');

        try {
            $path   = $this->service->writeSidecarLocal($raw, 4, 'Blue');
            $result = $this->service->readSidecarLocal($raw);

            $this->assertFileExists($sidecar);
            $this->assertSame($sidecar, $path);
            $this->assertNotNull($result);
            $this->assertSame(4,      $result['rating']);
            $this->assertSame('Blue', $result['label']);
            $this->assertGreaterThan(0, $result['mtime']);
        } finally {
            @unlink($raw);
            @unlink($sidecar);
        }
    }

    public function testReadSidecarLocalReturnsNullWhenMissing(): void
    {
        $result = $this->service->readSidecarLocal('/nonexistent/IMG_9999.cr3');
        $this->assertNull($result);
    }

    public function testWriteSidecarLocalFailsOnUnwritableDir(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->writeSidecarLocal('/root/no_permission/IMG_1.cr3', 1, null);
    }

    // ─── Tests: Lokale Datei-Suche ────────────────────────────────────────────

    public function testFindMatchingLocalFiles(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'starrate_find_test_' . uniqid();
        mkdir($dir);

        try {
            file_put_contents($dir . '/IMG_1234.cr3', 'raw');
            file_put_contents($dir . '/IMG_1234.jpg', 'jpeg');
            file_put_contents($dir . '/IMG_5678.jpg', 'other');

            $matches = $this->service->findMatchingLocalFiles($dir, 'IMG_1234');

            $this->assertCount(2, $matches);
            $bases = array_map('basename', $matches);
            $this->assertContains('IMG_1234.cr3', $bases);
            $this->assertContains('IMG_1234.jpg', $bases);
        } finally {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }

    public function testFindMatchingLocalFilesReturnsEmptyForMissingDir(): void
    {
        $result = $this->service->findMatchingLocalFiles('/nonexistent/path', 'IMG_1234');
        $this->assertSame([], $result);
    }

    // ─── Tests: Nextcloud Folder Mock ────────────────────────────────────────

    public function testWriteSidecarToNcFolder(): void
    {
        $written  = null;
        $filename = null;

        $folder = $this->createMock(Folder::class);
        $folder->method('nodeExists')->willReturn(false);
        $folder->method('newFile')
               ->willReturnCallback(function (string $name, string $content) use (&$filename, &$written) {
                   $filename = $name;
                   $written  = $content;
                   return $this->createMock(File::class);
               });

        $this->service->writeSidecar($folder, 'IMG_1234', 3, 'Yellow');

        $this->assertSame('IMG_1234.xmp', $filename);
        $this->assertStringContainsString('xmp:Rating="3"',    $written);
        $this->assertStringContainsString('xmp:Label="Yellow"', $written);
    }

    public function testReadSidecarFromNcFolderReturnsNullWhenMissing(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('nodeExists')->willReturn(false);

        $result = $this->service->readSidecar($folder, 'IMG_1234');
        $this->assertNull($result);
    }

    // ─── Tests: CR3-Fixture ───────────────────────────────────────────────────

    public function testDummyCr3FileExists(): void
    {
        $path = __DIR__ . '/../../fixtures/dummy.cr3';
        if (!file_exists($path)) {
            $this->markTestSkipped('Fixture dummy.cr3 nicht vorhanden');
        }
        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
    }
}

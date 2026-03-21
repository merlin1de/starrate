<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Service;

use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\SyncService;
use OCA\StarRate\Service\TagService;
use OCA\StarRate\Service\XmpService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SyncServiceTest extends TestCase
{
    private SyncService $service;

    /** @var IConfig&MockObject */
    private IConfig $config;
    /** @var IRootFolder&MockObject */
    private IRootFolder $rootFolder;
    /** @var TagService&MockObject */
    private TagService $tagService;
    /** @var ExifService&MockObject */
    private ExifService $exifService;
    /** @var XmpService&MockObject */
    private XmpService $xmpService;

    private const USER_ID = 'testuser';

    protected function setUp(): void
    {
        $this->config      = $this->createMock(IConfig::class);
        $this->rootFolder  = $this->createMock(IRootFolder::class);
        $this->tagService  = $this->createMock(TagService::class);
        $this->exifService = $this->createMock(ExifService::class);
        $this->xmpService  = $this->createMock(XmpService::class);
        $logger            = $this->createMock(LoggerInterface::class);

        $this->service = new SyncService(
            $this->config,
            $this->rootFolder,
            $this->tagService,
            $this->exifService,
            $this->xmpService,
            $logger,
        );
    }

    // ─── Tests: Zuordnungen verwalten ─────────────────────────────────────────

    public function testGetMappingsReturnsEmptyArrayInitially(): void
    {
        $this->config->method('getUserValue')->willReturn('[]');

        $mappings = $this->service->getMappings(self::USER_ID);
        $this->assertSame([], $mappings);
    }

    public function testAddMappingPersistsAndReturnsMapping(): void
    {
        $saved = null;
        $this->config->method('getUserValue')->willReturn('[]');
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $value) use (&$saved) {
                $saved = json_decode($value, true);
            });

        $mapping = $this->service->addMapping(
            self::USER_ID,
            '/Fotos/2024',
            '/Users/foto/2024',
            SyncService::DIRECTION_BOTH
        );

        $this->assertSame('/Fotos/2024',       $mapping['nc_path']);
        $this->assertSame('/Users/foto/2024',  $mapping['local_path']);
        $this->assertSame('bidirectional',     $mapping['direction']);
        $this->assertSame(SyncService::STATUS_NEVER, $mapping['status']);
        $this->assertNull($mapping['last_sync']);
        $this->assertSame(1, $mapping['id']);
        $this->assertNotNull($saved);
    }

    public function testAddMappingAutoIncrementsId(): void
    {
        $existing = [
            ['id' => 1, 'nc_path' => '/a', 'local_path' => '/b', 'direction' => 'bidirectional',
             'last_sync' => null, 'status' => 'never', 'log' => []],
            ['id' => 3, 'nc_path' => '/c', 'local_path' => '/d', 'direction' => 'nc_to_lr',
             'last_sync' => null, 'status' => 'never', 'log' => []],
        ];

        $this->config->method('getUserValue')->willReturn(json_encode($existing));
        $this->config->method('setUserValue');

        $mapping = $this->service->addMapping(self::USER_ID, '/e', '/f');
        $this->assertSame(4, $mapping['id']); // max(1,3)+1 = 4
    }

    public function testUpdateMappingChangesFields(): void
    {
        $existing = [[
            'id' => 1, 'nc_path' => '/old', 'local_path' => '/old-local',
            'direction' => 'nc_to_lr', 'last_sync' => null, 'status' => 'never', 'log' => [],
        ]];

        $saved = null;
        $this->config->method('getUserValue')->willReturn(json_encode($existing));
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $updated = $this->service->updateMapping(self::USER_ID, 1, [
            'nc_path'   => '/new',
            'direction' => 'lr_to_nc',
        ]);

        $this->assertSame('/new',     $updated['nc_path']);
        $this->assertSame('/old-local', $updated['local_path']); // unverändert
        $this->assertSame('lr_to_nc', $updated['direction']);
    }

    public function testDeleteMappingRemovesEntry(): void
    {
        $existing = [
            ['id' => 1, 'nc_path' => '/a', 'local_path' => '/b', 'direction' => 'bidirectional',
             'last_sync' => null, 'status' => 'never', 'log' => []],
            ['id' => 2, 'nc_path' => '/c', 'local_path' => '/d', 'direction' => 'nc_to_lr',
             'last_sync' => null, 'status' => 'never', 'log' => []],
        ];

        $saved = null;
        $this->config->method('getUserValue')->willReturn(json_encode($existing));
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$saved) { $saved = json_decode($v, true); });

        $this->service->deleteMapping(self::USER_ID, 1);

        $this->assertCount(1, $saved);
        $this->assertSame(2, $saved[0]['id']);
    }

    public function testGetMappingThrowsForUnknownId(): void
    {
        $this->config->method('getUserValue')->willReturn('[]');
        $this->expectException(\RuntimeException::class);
        $this->service->getMapping(self::USER_ID, 999);
    }

    public function testAddMappingThrowsForInvalidDirection(): void
    {
        $this->config->method('getUserValue')->willReturn('[]');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->addMapping(self::USER_ID, '/a', '/b', 'invalid_direction');
    }

    public function testUpdateMappingThrowsForUnknownId(): void
    {
        $this->config->method('getUserValue')->willReturn('[]');
        $this->expectException(\RuntimeException::class);
        $this->service->updateMapping(self::USER_ID, 999, ['nc_path' => '/x']);
    }

    public function testTrailingSlashIsNormalized(): void
    {
        $this->config->method('getUserValue')->willReturn('[]');
        $this->config->method('setUserValue');

        $mapping = $this->service->addMapping(self::USER_ID, '/Fotos/2024/', '/Users/foto/2024/');
        $this->assertSame('/Fotos/2024', $mapping['nc_path']);
        $this->assertSame('/Users/foto/2024', $mapping['local_path']);
    }

    // ─── Tests: Sync NC→LR ────────────────────────────────────────────────────

    public function testSyncNcToLrWritesSidecar(): void
    {
        $dir  = sys_get_temp_dir() . '/starrate_sync_test_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/IMG_0001.cr3', 'RAW_DUMMY');

        try {
            $mapping = [
                'id' => 1, 'nc_path' => '/Fotos', 'local_path' => $dir,
                'direction' => SyncService::DIRECTION_NC_TO_LR,
                'last_sync' => null, 'status' => 'never', 'log' => [],
            ];

            $this->config->method('getUserValue')->willReturn(json_encode([$mapping]));
            $this->config->method('setUserValue');

            // Mock: NC-Folder gibt ein JPEG zurück
            $jpegFile = $this->createMock(File::class);
            $jpegFile->method('getName')->willReturn('IMG_0001.jpg');
            $jpegFile->method('getMimeType')->willReturn('image/jpeg');

            $folder = $this->createMock(Folder::class);
            $folder->method('getDirectoryListing')->willReturn([$jpegFile]);

            $userFolder = $this->createMock(Folder::class);
            $userFolder->method('get')->willReturn($folder);

            $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

            // ExifService gibt Metadaten zurück
            $this->exifService->method('readMetadata')->willReturn(['rating' => 4, 'label' => 'Red']);

            // XmpService: isJpegFile, getBaseName, findMatchingLocalFiles, writeSidecarLocal
            $this->xmpService->method('isJpegFile')->willReturn(true);
            $this->xmpService->method('getBaseName')->willReturn('IMG_0001');
            $this->xmpService->method('isRawFile')->willReturn(true);
            $this->xmpService->method('findMatchingLocalFiles')->willReturn([$dir . '/IMG_0001.cr3']);
            $this->xmpService->method('writeSidecarLocal')->willReturn($dir . '/IMG_0001.xmp');

            $result = $this->service->runSync(self::USER_ID, 1);

            $this->assertSame(1, $result['synced']);
            $this->assertSame(0, $result['errors']);
        } finally {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }

    // ─── Tests: Konfliktlösung ────────────────────────────────────────────────

    public function testBidirectionalNcNewerWinsConflict(): void
    {
        $ncMtime    = time();
        $localMtime = $ncMtime - 3600; // NC ist 1 Stunde neuer

        $dir = sys_get_temp_dir() . '/starrate_conflict_test_' . uniqid();
        mkdir($dir);
        $sidecarPath = $dir . '/IMG_0001.xmp';
        file_put_contents($sidecarPath, '<dummy/>');
        touch($sidecarPath, $localMtime);

        try {
            $mapping = [
                'id' => 1, 'nc_path' => '/Fotos', 'local_path' => $dir,
                'direction' => SyncService::DIRECTION_BOTH,
                'last_sync' => null, 'status' => 'never', 'log' => [],
            ];

            $this->config->method('getUserValue')->willReturn(json_encode([$mapping]));
            $this->config->method('setUserValue');

            $jpegFile = $this->createMock(File::class);
            $jpegFile->method('getName')->willReturn('IMG_0001.jpg');
            $jpegFile->method('getMimeType')->willReturn('image/jpeg');
            $jpegFile->method('getMtime')->willReturn($ncMtime);
            $jpegFile->method('getId')->willReturn(42);

            $folder = $this->createMock(Folder::class);
            $folder->method('getDirectoryListing')->willReturn([$jpegFile]);

            $userFolder = $this->createMock(Folder::class);
            $userFolder->method('get')->willReturn($folder);
            $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

            $this->xmpService->method('isJpegFile')->willReturn(true);
            $this->xmpService->method('getBaseName')->willReturn('IMG_0001');
            $this->xmpService->method('isRawFile')->willReturn(false);
            $this->xmpService->method('findMatchingLocalFiles')->willReturn([]);
            $this->exifService->method('readMetadata')->willReturn(['rating' => 5, 'label' => 'Blue']);
            // NC ist neuer → writeSidecarLocal wird aufgerufen (nicht tagService)
            $this->xmpService->expects($this->once())
                ->method('writeSidecarLocal')
                ->willReturn($dir . '/IMG_0001.xmp');
            $this->tagService->expects($this->never())->method('setMetadata');

            $result = $this->service->runSync(self::USER_ID, 1);
            $this->assertSame(0, $result['errors']);
        } finally {
            @unlink($sidecarPath);
            @rmdir($dir);
        }
    }

    // ─── Tests: Sync-Log ─────────────────────────────────────────────────────

    public function testSyncLogContainsEntries(): void
    {
        $dir = sys_get_temp_dir() . '/starrate_log_test_' . uniqid();
        mkdir($dir);

        try {
            $mapping = [
                'id' => 1, 'nc_path' => '/Fotos', 'local_path' => $dir,
                'direction' => SyncService::DIRECTION_NC_TO_LR,
                'last_sync' => null, 'status' => 'never', 'log' => [],
            ];
            $this->config->method('getUserValue')->willReturn(json_encode([$mapping]));
            $this->config->method('setUserValue');

            $folder = $this->createMock(Folder::class);
            $folder->method('getDirectoryListing')->willReturn([]);
            $userFolder = $this->createMock(Folder::class);
            $userFolder->method('get')->willReturn($folder);
            $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

            $result = $this->service->runSync(self::USER_ID, 1);
            $this->assertIsArray($result['log']);
        } finally {
            @rmdir($dir);
        }
    }

    public function testSyncLogIsRolledAtMaxEntries(): void
    {
        // 12 bestehende Log-Einträge → nach Sync maximal 10
        $oldLog = array_fill(0, 12, '[2024-01-01 00:00:00] alter Eintrag');

        $mapping = [
            'id' => 1, 'nc_path' => '/Fotos', 'local_path' => sys_get_temp_dir(),
            'direction' => SyncService::DIRECTION_NC_TO_LR,
            'last_sync' => null, 'status' => 'never', 'log' => $oldLog,
        ];

        $savedMappings = null;
        $this->config->method('getUserValue')->willReturn(json_encode([$mapping]));
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$savedMappings) {
                $savedMappings = json_decode($v, true);
            });

        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([]);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willReturn($folder);
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

        $this->service->runSync(self::USER_ID, 1);

        $this->assertLessThanOrEqual(10, count($savedMappings[0]['log']));
    }

    // ─── Tests: Status-Update ─────────────────────────────────────────────────

    public function testSyncStatusBecomesOkOnSuccess(): void
    {
        $mapping = [
            'id' => 1, 'nc_path' => '/Fotos', 'local_path' => sys_get_temp_dir(),
            'direction' => SyncService::DIRECTION_NC_TO_LR,
            'last_sync' => null, 'status' => 'never', 'log' => [],
        ];

        $savedMappings = null;
        $this->config->method('getUserValue')->willReturn(json_encode([$mapping]));
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($u, $a, $k, $v) use (&$savedMappings) {
                $savedMappings = json_decode($v, true);
            });

        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([]);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willReturn($folder);
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

        $this->service->runSync(self::USER_ID, 1);

        $this->assertSame(SyncService::STATUS_OK, $savedMappings[0]['status']);
        $this->assertNotNull($savedMappings[0]['last_sync']);
    }
}

<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Controller;

use OCA\StarRate\Controller\RatingController;
use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\ShareService;
use OCA\StarRate\Service\TagService;
use OCA\StarRate\Settings\UserSettings;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RatingControllerTest extends TestCase
{
    private RatingController $controller;

    /** @var IRequest&MockObject */
    private IRequest $request;
    /** @var IRootFolder&MockObject */
    private IRootFolder $rootFolder;
    /** @var IUserSession&MockObject */
    private IUserSession $userSession;
    /** @var TagService&MockObject */
    private TagService $tagService;
    /** @var ExifService&MockObject */
    private ExifService $exifService;
    /** @var UserSettings&MockObject */
    private UserSettings $userSettings;
    /** @var ShareService&MockObject */
    private ShareService $shareService;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private const USER_ID = 'testuser';
    private const FILE_ID = 1234;

    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->rootFolder   = $this->createMock(IRootFolder::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->tagService   = $this->createMock(TagService::class);
        $this->exifService  = $this->createMock(ExifService::class);
        $this->userSettings = $this->createMock(UserSettings::class);
        $this->shareService = $this->createMock(ShareService::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn(self::USER_ID);
        $this->userSession->method('getUser')->willReturn($user);

        // Default: write_xmp aktiv, comments_enabled aktiv
        $this->userSettings->method('getSettings')->willReturn([
            'write_xmp'          => true,
            'xmp_label_language' => 'en',
            'comments_enabled' => true,
        ]);

        $this->controller = new RatingController(
            'starrate',
            $this->request,
            $this->rootFolder,
            $this->userSession,
            $this->tagService,
            $this->exifService,
            $this->userSettings,
            $this->shareService,
            $this->logger,
        );
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    private function mockFileById(int $fileId, string $mime = 'image/jpeg'): File&MockObject
    {
        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getId')->willReturn($fileId);

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->with($fileId)->willReturn([$file]);
        $this->rootFolder->method('getUserFolder')->with(self::USER_ID)->willReturn($userFolder);

        return $file;
    }

    private function mockFileNotFound(int $fileId): void
    {
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->with($fileId)->willReturn([]);
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
    }

    private function mockJsonBody(array $data): void
    {
        $this->request->method('getParams')->willReturn($data);
    }

    // ─── Tests: GET /api/rating/{fileId} ─────────────────────────────────────

    public function testGetReturnsMetadata(): void
    {
        $this->mockFileById(self::FILE_ID);
        $this->tagService->method('getMetadata')
            ->with((string) self::FILE_ID)
            ->willReturn(['rating' => 3, 'color' => 'Green', 'pick' => 'none']);

        $response = $this->controller->get(self::FILE_ID);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(3,       $data['rating']);
        $this->assertSame('Green', $data['color']);
        $this->assertSame('none',  $data['pick']);
    }

    public function testGetReturns404ForUnknownFile(): void
    {
        $this->mockFileNotFound(self::FILE_ID);

        $response = $this->controller->get(self::FILE_ID);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    // ─── Tests: POST /api/rating/{fileId} ────────────────────────────────────

    public function testSetRatingWritesTagAndExif(): void
    {
        $file = $this->mockFileById(self::FILE_ID, 'image/jpeg');
        $this->mockJsonBody(['rating' => 4]);

        $this->tagService->expects($this->once())
            ->method('setMetadata')
            ->with((string) self::FILE_ID, ['rating' => 4]);

        $this->exifService->expects($this->once())
            ->method('writeMetadata')
            ->with($file, 4, null);

        $this->tagService->method('getMetadata')
            ->willReturn(['rating' => 4, 'color' => null, 'pick' => 'none']);

        $response = $this->controller->set(self::FILE_ID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(4, $response->getData()['rating']);
    }

    public function testSetColorWritesTagAndExif(): void
    {
        $file = $this->mockFileById(self::FILE_ID, 'image/jpeg');
        $this->mockJsonBody(['color' => 'Blue']);

        $this->tagService->expects($this->once())
            ->method('setMetadata')
            ->with((string) self::FILE_ID, ['color' => 'Blue']);

        $this->exifService->expects($this->once())
            ->method('writeMetadata')
            ->with($file, null, 'Blue');

        $this->tagService->method('getMetadata')
            ->willReturn(['rating' => 0, 'color' => 'Blue', 'pick' => 'none']);

        $response = $this->controller->set(self::FILE_ID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testSetDoesNotWriteExifForNonJpeg(): void
    {
        $this->mockFileById(self::FILE_ID, 'image/png');
        $this->mockJsonBody(['rating' => 3]);

        $this->tagService->expects($this->once())->method('setMetadata');
        $this->exifService->expects($this->never())->method('writeMetadata');
        $this->tagService->method('getMetadata')->willReturn(['rating' => 3, 'color' => null, 'pick' => 'none']);

        $response = $this->controller->set(self::FILE_ID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testSetReturns422ForRating6(): void
    {
        $this->mockFileById(self::FILE_ID);
        $this->mockJsonBody(['rating' => 6]);

        $response = $this->controller->set(self::FILE_ID);
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }

    public function testSetReturns422ForNegativeRating(): void
    {
        $this->mockFileById(self::FILE_ID);
        $this->mockJsonBody(['rating' => -1]);

        $response = $this->controller->set(self::FILE_ID);
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }

    public function testSetReturns422ForUnknownColor(): void
    {
        $this->mockFileById(self::FILE_ID);
        $this->mockJsonBody(['color' => 'Magenta']);

        $response = $this->controller->set(self::FILE_ID);
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }

    public function testSetReturns422ForInvalidPick(): void
    {
        $this->mockFileById(self::FILE_ID);
        $this->mockJsonBody(['pick' => 'maybe']);

        $response = $this->controller->set(self::FILE_ID);
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }

    public function testSetReturns404ForUnknownFile(): void
    {
        $this->mockFileNotFound(self::FILE_ID);
        $this->mockJsonBody(['rating' => 3]);

        $response = $this->controller->set(self::FILE_ID);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testSetEmptyColorRemovesLabel(): void
    {
        $file = $this->mockFileById(self::FILE_ID, 'image/jpeg');
        $this->mockJsonBody(['color' => '']);

        $this->tagService->expects($this->once())
            ->method('setMetadata')
            ->with((string) self::FILE_ID, ['color' => null]);

        $this->exifService->expects($this->once())
            ->method('writeMetadata')
            ->with($file, null, '');

        $this->tagService->method('getMetadata')
            ->willReturn(['rating' => 0, 'color' => null, 'pick' => 'none']);

        $response = $this->controller->set(self::FILE_ID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    // ─── Tests: POST /api/rating/batch ───────────────────────────────────────

    public function testSetBatchRatesMultipleFiles(): void
    {
        $fileIds = [1, 2, 3];

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturnCallback(function (int $id) {
            $file = $this->createMock(File::class);
            $file->method('getMimeType')->willReturn('image/jpeg');
            $file->method('getId')->willReturn($id);
            return [$file];
        });
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $this->mockJsonBody(['fileIds' => $fileIds, 'rating' => 5]);

        $this->tagService->expects($this->exactly(3))->method('setMetadata');
        $this->exifService->expects($this->exactly(3))->method('writeMetadata');

        $response = $this->controller->setBatch();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(3, $response->getData()['updated']);
        $this->assertSame(0, $response->getData()['errors']);
    }

    public function testSetBatchReturns400WhenFileIdsMissing(): void
    {
        $this->mockJsonBody(['rating' => 3]);

        $response = $this->controller->setBatch();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testSetBatchReturns400ForTooManyFiles(): void
    {
        $this->mockJsonBody(['fileIds' => range(1, 501), 'rating' => 3]);

        $response = $this->controller->setBatch();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testSetBatchReturns422ForInvalidRating(): void
    {
        $this->mockJsonBody(['fileIds' => [1, 2], 'rating' => 7]);

        $response = $this->controller->setBatch();
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }

    public function testSetBatchCountsErrorsForMissingFiles(): void
    {
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([]); // alle nicht gefunden
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $this->mockJsonBody(['fileIds' => [1, 2, 3], 'rating' => 4]);

        $response = $this->controller->setBatch();
        $this->assertSame(0, $response->getData()['updated']);
        $this->assertSame(3, $response->getData()['errors']);
    }

    // ─── Tests: POST /api/rating/{fileId} – Pick & Multi-Field ─────────────

    public function testSetPickWritesTag(): void
    {
        $file = $this->mockFileById(self::FILE_ID, 'image/jpeg');
        $this->mockJsonBody(['pick' => 'reject']);

        $this->tagService->method('setMetadata');
        $this->tagService->method('getMetadata')
            ->willReturn(['rating' => 0, 'color' => null, 'pick' => 'reject']);

        // Pick-only: writeMetadata gets null for both rating and color (= no EXIF change)
        $this->exifService->expects($this->once())
            ->method('writeMetadata')
            ->with($file, null, null);

        $response = $this->controller->set(self::FILE_ID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('reject', $response->getData()['pick']);
    }

    public function testSetMultipleFieldsAtOnce(): void
    {
        $file = $this->mockFileById(self::FILE_ID, 'image/jpeg');
        $this->mockJsonBody(['rating' => 5, 'color' => 'Red', 'pick' => 'pick']);

        $this->tagService->expects($this->once())
            ->method('setMetadata')
            ->with((string) self::FILE_ID, ['rating' => 5, 'color' => 'Red', 'pick' => 'pick']);

        $this->exifService->expects($this->once())
            ->method('writeMetadata')
            ->with($file, 5, 'Red');

        $this->tagService->method('getMetadata')
            ->willReturn(['rating' => 5, 'color' => 'Red', 'pick' => 'pick']);

        $response = $this->controller->set(self::FILE_ID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(5, $response->getData()['rating']);
        $this->assertSame('Red', $response->getData()['color']);
        $this->assertSame('pick', $response->getData()['pick']);
    }

    // ─── Tests: DELETE /api/rating/{fileId} ──────────────────────────────────

    public function testDeleteClearsAllTags(): void
    {
        $file = $this->mockFileById(self::FILE_ID, 'image/jpeg');

        $this->tagService->expects($this->once())
            ->method('clearAll')
            ->with((string) self::FILE_ID);

        $this->exifService->expects($this->once())
            ->method('writeMetadata')
            ->with($file, 0, '');

        $response = $this->controller->delete(self::FILE_ID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['ok']);
    }

    public function testDeleteSkipsExifForNonJpeg(): void
    {
        $this->mockFileById(self::FILE_ID, 'image/png');

        $this->tagService->expects($this->once())->method('clearAll');
        $this->exifService->expects($this->never())->method('writeMetadata');

        $response = $this->controller->delete(self::FILE_ID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testDeleteReturns404ForUnknownFile(): void
    {
        $this->mockFileNotFound(self::FILE_ID);

        $response = $this->controller->delete(self::FILE_ID);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    // ─── Tests: Kommentare (Owner) ──────────────────────────────────────────

    public function testGetCommentReturnsData(): void
    {
        $this->shareService->method('getComment')
            ->with(self::FILE_ID)
            ->willReturn([
                'file_id'     => self::FILE_ID,
                'comment'     => 'Tolles Licht!',
                'author_type' => 'owner',
                'author_name' => self::USER_ID,
                'updated_at'  => 1713000000,
            ]);

        $response = $this->controller->getComment(self::FILE_ID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('Tolles Licht!', $response->getData()['comment']);
        $this->assertSame(self::USER_ID, $response->getData()['author_name']);
        $this->assertSame(1713000000, $response->getData()['updated_at']);
    }

    public function testGetCommentReturnsNullWhenEmpty(): void
    {
        $this->shareService->method('getComment')->willReturn(null);

        $response = $this->controller->getComment(self::FILE_ID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertNull($response->getData()['comment']);
    }

    public function testGetCommentReturns403WhenDisabled(): void
    {
        $this->userSettings = $this->createMock(UserSettings::class);
        $this->userSettings->method('getSettings')->willReturn([
            'write_xmp'          => true,
            'xmp_label_language' => 'en',
            'comments_enabled' => false,
        ]);

        $controller = new RatingController(
            'starrate', $this->request, $this->rootFolder,
            $this->userSession, $this->tagService, $this->exifService,
            $this->userSettings, $this->shareService, $this->logger,
        );

        $response = $controller->getComment(self::FILE_ID);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testSaveCommentCallsService(): void
    {
        $this->mockJsonBody(['comment' => 'Schönes Bild']);
        $this->shareService->expects($this->once())
            ->method('saveComment')
            ->with(self::FILE_ID, 'Schönes Bild', 'owner', self::USER_ID)
            ->willReturn([
                'file_id' => self::FILE_ID, 'comment' => 'Schönes Bild',
                'author_type' => 'owner', 'author_name' => self::USER_ID, 'updated_at' => time(),
            ]);

        $response = $this->controller->saveComment(self::FILE_ID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('Schönes Bild', $response->getData()['comment']);
    }

    public function testSaveCommentReturns422WhenEmpty(): void
    {
        $this->mockJsonBody(['comment' => '   ']);

        $response = $this->controller->saveComment(self::FILE_ID);
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }

    public function testSaveCommentReturns403WhenDisabled(): void
    {
        $this->userSettings = $this->createMock(UserSettings::class);
        $this->userSettings->method('getSettings')->willReturn([
            'write_xmp'          => true,
            'xmp_label_language' => 'en',
            'comments_enabled' => false,
        ]);

        $controller = new RatingController(
            'starrate', $this->request, $this->rootFolder,
            $this->userSession, $this->tagService, $this->exifService,
            $this->userSettings, $this->shareService, $this->logger,
        );

        $this->mockJsonBody(['comment' => 'Test']);
        $response = $controller->saveComment(self::FILE_ID);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testDeleteCommentCallsService(): void
    {
        $this->shareService->expects($this->once())
            ->method('deleteComment')
            ->with(self::FILE_ID);

        $response = $this->controller->deleteComment(self::FILE_ID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['ok']);
    }

    public function testDeleteCommentReturns403WhenDisabled(): void
    {
        $this->userSettings = $this->createMock(UserSettings::class);
        $this->userSettings->method('getSettings')->willReturn([
            'write_xmp'          => true,
            'xmp_label_language' => 'en',
            'comments_enabled' => false,
        ]);

        $controller = new RatingController(
            'starrate', $this->request, $this->rootFolder,
            $this->userSession, $this->tagService, $this->exifService,
            $this->userSettings, $this->shareService, $this->logger,
        );

        $response = $controller->deleteComment(self::FILE_ID);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    // ─── Tests: Nicht authentifiziert ────────────────────────────────────────

    public function testGetReturns401WhenNotAuthenticated(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);

        $controller = new RatingController(
            'starrate', $this->request, $this->rootFolder,
            $this->userSession, $this->tagService, $this->exifService,
            $this->userSettings, $this->shareService,
            $this->createMock(LoggerInterface::class),
        );

        $response = $controller->get(self::FILE_ID);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }
}

<?php

declare(strict_types=1);

namespace OCA\StarRate\Controller;

use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\TagService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IPreview as IPreviewManager;
use Psr\Log\LoggerInterface;

class GalleryController extends Controller
{
    // Unterstützte Bildformate
    private const SUPPORTED_MIME = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/tiff',
        'image/heic',
        'image/heif',
    ];

    public function __construct(
        string                        $appName,
        IRequest                      $request,
        private readonly IRootFolder  $rootFolder,
        private readonly IUserSession $userSession,
        private readonly TagService   $tagService,
        private readonly ExifService  $exifService,
        private readonly IPreviewManager $previewManager,
        private readonly IURLGenerator   $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ─── Seiten ───────────────────────────────────────────────────────────────

    /**
     * Hauptseite der App — lädt Vue.js SPA.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        return new TemplateResponse($this->appName, 'index');
    }

    /**
     * Ordner-Ansicht — wird clientseitig durch Vue Router gehandhabt.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function folder(string $path): TemplateResponse
    {
        return new TemplateResponse($this->appName, 'index');
    }

    // ─── API: Bilder abrufen ─────────────────────────────────────────────────

    /**
     * Gibt alle Bilder im angegebenen Ordner zurück, inkl. Metadaten.
     *
     * GET /api/images?path=/Fotos/2024&sort=name&order=asc
     *
     * @return DataResponse<array{
     *     images: array,
     *     folder: string,
     *     total: int
     * }>
     */
    #[NoAdminRequired]
    public function images(): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        $path  = $this->request->getParam('path', '/');
        $sort  = $this->request->getParam('sort', 'name');   // name | mtime | size
        $order = $this->request->getParam('order', 'asc');   // asc | desc

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $folder     = $path === '/' ? $userFolder : $userFolder->get(ltrim($path, '/'));

            if (!($folder instanceof Folder)) {
                return new DataResponse(['error' => 'Pfad ist kein Ordner'], Http::STATUS_BAD_REQUEST);
            }

            $images  = $this->listImages($folder, $sort, $order);
            $fileIds = array_column($images, 'id');

            // Metadaten als Batch laden (eine SQL-Abfrage)
            $metadata = $this->tagService->getMetadataBatch(
                array_map('strval', $fileIds)
            );

            // Metadaten in Bild-Array einmergen
            foreach ($images as &$img) {
                $id         = (string) $img['id'];
                $meta       = $metadata[$id] ?? ['rating' => 0, 'color' => null, 'pick' => 'none'];
                $img['rating'] = $meta['rating'];
                $img['color']  = $meta['color'];
                $img['pick']   = $meta['pick'];
            }
            unset($img);

            // Unterordner sammeln
            $folders = [];
            foreach ($folder->getDirectoryListing() as $node) {
                if ($node instanceof Folder) {
                    $folders[] = ['name' => $node->getName(), 'path' => $path === '/' ? '/' . $node->getName() : $path . '/' . $node->getName()];
                }
            }
            usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));

            return new DataResponse([
                'images'  => $images,
                'folders' => $folders,
                'folder'  => $path,
                'total'   => count($images),
            ]);

        } catch (NotFoundException) {
            return new DataResponse(['error' => "Ordner nicht gefunden: {$path}"], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('StarRate GalleryController::images – ' . $e->getMessage());
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── API: Thumbnail ───────────────────────────────────────────────────────

    /**
     * Liefert ein Thumbnail für eine Datei (nutzt NC Preview-API).
     *
     * GET /api/thumbnail/{fileId}?width=300&height=300
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function thumbnail(int $fileId): Response
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        $width  = (int) ($this->request->getParam('width', 300));
        $height = (int) ($this->request->getParam('height', 300));

        // Sicherheitsgrenzen
        $width  = min(max($width, 32), 1920);
        $height = min(max($height, 32), 1920);

        try {
            $file = $this->getFileById($userId, $fileId);
            if ($file === null) {
                return new DataResponse(['error' => 'Datei nicht gefunden'], Http::STATUS_NOT_FOUND);
            }

            $preview = $this->previewManager->getPreview($file, $width, $height, true);

            $response = new FileDisplayResponse($preview, Http::STATUS_OK, [
                'Content-Type' => $preview->getMimeType(),
            ]);
            $response->cacheFor(3600); // 1 Stunde
            return $response;

        } catch (\Exception $e) {
            $this->logger->warning("StarRate: Thumbnail-Fehler für {$fileId}: " . $e->getMessage());
            return new DataResponse(['error' => 'Vorschau nicht verfügbar'], Http::STATUS_NOT_FOUND);
        }
    }

    // ─── API: Vollbild-Preview ────────────────────────────────────────────────

    /**
     * Liefert ein hochauflösendes Preview für die Lupenansicht.
     *
     * GET /api/preview/{fileId}?width=1920&height=1200
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function preview(int $fileId): Response
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        $width  = (int) ($this->request->getParam('width', 1920));
        $height = (int) ($this->request->getParam('height', 1200));

        $width  = min(max($width, 100), 3840);
        $height = min(max($height, 100), 2160);

        try {
            $file = $this->getFileById($userId, $fileId);
            if ($file === null) {
                return new DataResponse(['error' => 'Datei nicht gefunden'], Http::STATUS_NOT_FOUND);
            }

            $preview = $this->previewManager->getPreview($file, $width, $height, false);

            $response = new FileDisplayResponse($preview, Http::STATUS_OK, [
                'Content-Type' => $preview->getMimeType(),
            ]);
            $response->cacheFor(1800);
            return $response;

        } catch (\Exception $e) {
            $this->logger->warning("StarRate: Preview-Fehler für {$fileId}: " . $e->getMessage());
            return new DataResponse(['error' => 'Vorschau nicht verfügbar'], Http::STATUS_NOT_FOUND);
        }
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    /**
     * Listet alle unterstützten Bilder in einem Ordner auf.
     *
     * @return array<int, array{
     *     id: int, name: string, path: string,
     *     size: int, mtime: int, mimetype: string,
     *     width: int|null, height: int|null
     * }>
     */
    private function listImages(Folder $folder, string $sort, string $order): array
    {
        $images = [];

        foreach ($folder->getDirectoryListing() as $node) {
            if (!($node instanceof File)) {
                continue;
            }

            $mime = $node->getMimeType();
            if (!in_array($mime, self::SUPPORTED_MIME, true)) {
                continue;
            }

            $images[] = [
                'id'       => $node->getId(),
                'name'     => $node->getName(),
                'path'     => $node->getPath(),
                'size'     => $node->getSize(),
                'mtime'    => $node->getMtime(),
                'mimetype' => $mime,
                'width'    => null,  // wird lazy im Frontend geladen
                'height'   => null,
            ];
        }

        usort($images, function (array $a, array $b) use ($sort, $order): int {
            $cmp = match ($sort) {
                'mtime' => $a['mtime'] <=> $b['mtime'],
                'size'  => $a['size']  <=> $b['size'],
                default => strcasecmp($a['name'], $b['name']),
            };
            return $order === 'desc' ? -$cmp : $cmp;
        });

        return array_values($images);
    }

    /**
     * Sucht eine Datei anhand der ID im User-Ordner.
     */
    private function getFileById(string $userId, int $fileId): ?File
    {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $nodes      = $userFolder->getById($fileId);

        foreach ($nodes as $node) {
            if ($node instanceof File) {
                return $node;
            }
        }
        return null;
    }

    private function getUserId(): ?string
    {
        $user = $this->userSession->getUser();
        return $user?->getUID();
    }
}

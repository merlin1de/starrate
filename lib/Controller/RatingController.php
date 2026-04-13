<?php

declare(strict_types=1);

namespace OCA\StarRate\Controller;

use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\TagService;
use OCA\StarRate\Settings\UserSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class RatingController extends Controller
{
    use StarRateControllerTrait;

    public function __construct(
        string                        $appName,
        IRequest                      $request,
        private readonly IRootFolder  $rootFolder,
        private readonly IUserSession $userSession,
        private readonly TagService   $tagService,
        private readonly ExifService  $exifService,
        private readonly UserSettings $userSettings,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ─── GET /api/rating/{fileId} ─────────────────────────────────────────────

    /**
     * Liest Rating, Color und Pick-Status einer Datei.
     *
     * @return DataResponse<array{rating: int, color: string|null, pick: string}>
     */
    #[NoAdminRequired]
    public function get(int $fileId): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $file = $this->getFileById($userId, $fileId);
        if ($file === null) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $meta = $this->tagService->getMetadata((string) $fileId);
            return new DataResponse($meta);
        } catch (\Exception $e) {
            $this->logger->error("StarRate RatingController::get – {$e->getMessage()}");
            return new DataResponse(['error' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── POST /api/rating/{fileId} ────────────────────────────────────────────

    /**
     * Setzt Rating und/oder Color und/oder Pick für eine Datei.
     * Schreibt in NC-Tags UND direkt in JPEG-XMP.
     *
     * Body (JSON): { "rating": 4, "color": "Green", "pick": "pick" }
     * Alle Felder optional — nur gesendete Felder werden geändert.
     *
     * @return DataResponse<array{rating: int, color: string|null, pick: string}>
     */
    #[NoAdminRequired]
    public function set(int $fileId): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $body = $this->getJsonBody();

        // Validierung
        $errors = $this->validateRatingBody($body);
        if (!empty($errors)) {
            return new DataResponse(['error' => implode('; ', $errors)], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $file = $this->getFileById($userId, $fileId);
        if ($file === null) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $fileIdStr = (string) $fileId;
            $data      = [];

            if (array_key_exists('rating', $body)) {
                $data['rating'] = (int) $body['rating'];
            }
            if (array_key_exists('color', $body)) {
                $data['color'] = $body['color'] === '' ? null : $body['color'];
            }
            if (array_key_exists('pick', $body)) {
                $data['pick'] = $body['pick'];
            }

            // 1. NC-Tags setzen
            $this->tagService->setMetadata($fileIdStr, $data);

            // 2. JPEG-XMP schreiben — nur wenn JPEG und in den Einstellungen aktiviert
            $mime = $file->getMimeType();
            if (in_array($mime, ['image/jpeg', 'image/jpg'], true)
                && $this->userSettings->getSettings($userId)['write_xmp']) {
                try {
                    $this->exifService->writeMetadata(
                        $file,
                        $data['rating'] ?? null,
                        array_key_exists('color', $data) ? ($data['color'] ?? '') : null,
                    );
                } catch (\Exception $e) {
                    $this->logger->warning("StarRate: XMP write skipped for {$fileId}: " . $e->getMessage());
                }
            }

            // Aktuellen Stand zurückgeben
            $updated = $this->tagService->getMetadata($fileIdStr);
            return new DataResponse($updated, Http::STATUS_OK);

        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            $this->logger->error("StarRate RatingController::set – {$e->getMessage()}");
            return new DataResponse(['error' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── POST /api/rating/batch ───────────────────────────────────────────────

    /**
     * Stapel-Bewertung: setzt Rating/Color/Pick für mehrere Dateien gleichzeitig.
     *
     * Body (JSON): {
     *   "fileIds": [123, 456, 789],
     *   "rating": 4,          // optional
     *   "color": "Green",     // optional
     *   "pick": "pick"        // optional
     * }
     *
     * @return DataResponse<array{updated: int, errors: int, details: array}>
     */
    #[NoAdminRequired]
    public function setBatch(): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $body = $this->getJsonBody();

        if (empty($body['fileIds']) || !is_array($body['fileIds'])) {
            return new DataResponse(['error' => 'fileIds missing or not an array'], Http::STATUS_BAD_REQUEST);
        }

        if (count($body['fileIds']) > 500) {
            return new DataResponse(['error' => 'Maximum 500 files per batch'], Http::STATUS_BAD_REQUEST);
        }

        $errors = $this->validateRatingBody($body);
        if (!empty($errors)) {
            return new DataResponse(['error' => implode('; ', $errors)], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $data = [];
        if (array_key_exists('rating', $body)) {
            $data['rating'] = (int) $body['rating'];
        }
        if (array_key_exists('color', $body)) {
            $data['color'] = $body['color'] === '' ? null : $body['color'];
        }
        if (array_key_exists('pick', $body)) {
            $data['pick'] = $body['pick'];
        }

        $updated    = 0;
        $errCount   = 0;
        $xmpWritten = 0;
        $xmpSkipped = 0;
        $details    = [];
        $writeXmp   = $this->userSettings->getSettings($userId)['write_xmp'];

        foreach ($body['fileIds'] as $rawId) {
            $fileId = (int) $rawId;

            try {
                $file = $this->getFileById($userId, $fileId);
                if ($file === null) {
                    $errCount++;
                    $details[] = ['id' => $fileId, 'error' => 'Not found'];
                    continue;
                }

                // NC-Tags
                $this->tagService->setMetadata((string) $fileId, $data);

                // JPEG-XMP — non-fatal, nur wenn in den Einstellungen aktiviert
                $mime = $file->getMimeType();
                if (in_array($mime, ['image/jpeg', 'image/jpg'], true) && $writeXmp) {
                    try {
                        $this->exifService->writeMetadata(
                            $file,
                            $data['rating'] ?? null,
                            array_key_exists('color', $data) ? ($data['color'] ?? '') : null,
                        );
                        $xmpWritten++;
                    } catch (\Exception $e) {
                        $xmpSkipped++;
                        $this->logger->warning("StarRate: XMP write skipped for {$fileId}: " . $e->getMessage());
                    }
                }

                $updated++;
                $details[] = ['id' => $fileId, 'ok' => true];

            } catch (\Exception $e) {
                $errCount++;
                $details[] = ['id' => $fileId, 'error' => $e->getMessage()];
                $this->logger->warning("StarRate batch rating error for {$fileId}: " . $e->getMessage());
            }
        }

        return new DataResponse([
            'updated'     => $updated,
            'errors'      => $errCount,
            'xmpWritten'  => $xmpWritten,
            'xmpSkipped'  => $xmpSkipped,
            'details'     => $details,
        ], Http::STATUS_OK);
    }

    // ─── DELETE /api/rating/{fileId} ──────────────────────────────────────────

    /**
     * Entfernt alle StarRate-Tags von einer Datei und setzt XMP auf Rating=0, kein Label.
     */
    #[NoAdminRequired]
    public function delete(int $fileId): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $file = $this->getFileById($userId, $fileId);
        if ($file === null) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $this->tagService->clearAll((string) $fileId);

            $mime = $file->getMimeType();
            if (in_array($mime, ['image/jpeg', 'image/jpg'], true)
                && $this->userSettings->getSettings($userId)['write_xmp']) {
                $this->exifService->writeMetadata($file, 0, '');
            }

            return new DataResponse(['ok' => true]);
        } catch (\Exception $e) {
            $this->logger->error("StarRate RatingController::delete – {$e->getMessage()}");
            return new DataResponse(['error' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    private function validateRatingBody(array $body): array
    {
        $errors = [];

        if (array_key_exists('rating', $body)) {
            $r = $body['rating'];
            if (!is_int($r) && !ctype_digit((string) $r)) {
                $errors[] = 'rating must be an integer';
            } elseif ((int) $r < 0 || (int) $r > 5) {
                $errors[] = 'rating must be between 0 and 5';
            }
        }

        if (array_key_exists('color', $body) && $body['color'] !== '' && $body['color'] !== null) {
            if (!in_array($body['color'], TagService::VALID_COLORS, true)) {
                $errors[] = 'color must be one of ' . implode(', ', TagService::VALID_COLORS);
            }
        }

        if (array_key_exists('pick', $body)) {
            if (!in_array($body['pick'], TagService::VALID_PICKS, true)) {
                $errors[] = 'pick must be "pick", "reject" or "none"';
            }
        }

        return $errors;
    }

}

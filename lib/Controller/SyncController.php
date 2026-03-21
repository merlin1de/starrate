<?php

declare(strict_types=1);

namespace OCA\StarRate\Controller;

use OCA\StarRate\Service\SyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SyncController extends Controller
{
    public function __construct(
        string                        $appName,
        IRequest                      $request,
        private readonly SyncService  $syncService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ─── Sync-Seite ───────────────────────────────────────────────────────────

    /**
     * Sync-Panel — wird durch Vue Router in der SPA gerendert.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        return new TemplateResponse($this->appName, 'index');
    }

    // ─── GET /api/sync/mappings ───────────────────────────────────────────────

    /**
     * Gibt alle Sync-Zuordnungen des aktuellen Benutzers zurück.
     */
    #[NoAdminRequired]
    public function getMappings(): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $mappings = $this->syncService->getMappings($userId);
            return new DataResponse(['mappings' => $mappings]);
        } catch (\Exception $e) {
            $this->logger->error("StarRate SyncController::getMappings – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── POST /api/sync/mappings ──────────────────────────────────────────────

    /**
     * Erstellt eine neue Sync-Zuordnung.
     *
     * Body (JSON): {
     *   "nc_path": "/Fotos/2024",
     *   "local_path": "/Users/foto/Pictures/2024",
     *   "direction": "bidirectional"   // nc_to_lr | lr_to_nc | bidirectional
     * }
     */
    #[NoAdminRequired]
    public function addMapping(): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->getJsonBody();

        $errors = $this->validateMappingBody($body, required: true);
        if (!empty($errors)) {
            return new DataResponse(['error' => implode('; ', $errors)], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        try {
            $mapping = $this->syncService->addMapping(
                $userId,
                $body['nc_path'],
                $body['local_path'],
                $body['direction'] ?? SyncService::DIRECTION_BOTH,
            );
            return new DataResponse(['mapping' => $mapping], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            $this->logger->error("StarRate SyncController::addMapping – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── PUT /api/sync/mappings/{id} ──────────────────────────────────────────

    /**
     * Aktualisiert eine bestehende Zuordnung.
     *
     * Body (JSON): { "nc_path"?: "...", "local_path"?: "...", "direction"?: "..." }
     */
    #[NoAdminRequired]
    public function updateMapping(int $id): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        $body   = $this->getJsonBody();
        $errors = $this->validateMappingBody($body, required: false);
        if (!empty($errors)) {
            return new DataResponse(['error' => implode('; ', $errors)], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        try {
            $updated = $this->syncService->updateMapping($userId, $id, $body);
            return new DataResponse(['mapping' => $updated]);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            $this->logger->error("StarRate SyncController::updateMapping – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── DELETE /api/sync/mappings/{id} ───────────────────────────────────────

    /**
     * Löscht eine Zuordnung.
     */
    #[NoAdminRequired]
    public function deleteMapping(int $id): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->syncService->deleteMapping($userId, $id);
            return new DataResponse(['ok' => true]);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error("StarRate SyncController::deleteMapping – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── POST /api/sync/run/{id} ──────────────────────────────────────────────

    /**
     * Empfängt das Sync-Ergebnis vom lokalen sync-lr.ps1-Script.
     * Aktualisiert Status + Log der Zuordnung in der App.
     *
     * Body (JSON): {
     *   "synced":  5,
     *   "skipped": 2,
     *   "errors":  0,
     *   "log":     ["NC→LR: IMG_0001 → ★4 Red", ...]
     * }
     */
    #[NoAdminRequired]
    public function run(int $id): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->getJsonBody();

        try {
            $this->syncService->recordResult($userId, $id, $body);
            return new DataResponse(['ok' => true]);

        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error("StarRate SyncController::run – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── GET /api/sync/log/{id} ───────────────────────────────────────────────

    /**
     * Gibt das Sync-Log einer Zuordnung zurück (letzte 10 Einträge).
     */
    #[NoAdminRequired]
    public function getLog(int $id): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $mapping = $this->syncService->getMapping($userId, $id);
            return new DataResponse([
                'log'       => $mapping['log']       ?? [],
                'last_sync' => $mapping['last_sync']  ?? null,
                'status'    => $mapping['status']     ?? SyncService::STATUS_NEVER,
            ]);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error("StarRate SyncController::getLog – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── GET /api/sync/status/{id} ────────────────────────────────────────────

    /**
     * Gibt den aktuellen Sync-Status einer Zuordnung zurück.
     *
     * @return DataResponse<array{
     *   id: int, status: string, last_sync: int|null,
     *   nc_path: string, local_path: string, direction: string
     * }>
     */
    #[NoAdminRequired]
    public function getStatus(int $id): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $mapping = $this->syncService->getMapping($userId, $id);
            return new DataResponse([
                'id'         => $mapping['id'],
                'status'     => $mapping['status']     ?? SyncService::STATUS_NEVER,
                'last_sync'  => $mapping['last_sync']  ?? null,
                'nc_path'    => $mapping['nc_path'],
                'local_path' => $mapping['local_path'],
                'direction'  => $mapping['direction'],
            ]);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error("StarRate SyncController::getStatus – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    private function validateMappingBody(array $body, bool $required): array
    {
        $errors = [];

        if ($required) {
            if (empty($body['nc_path'])) {
                $errors[] = 'nc_path ist erforderlich';
            }
            if (empty($body['local_path'])) {
                $errors[] = 'local_path ist erforderlich';
            }
        }

        if (isset($body['direction'])) {
            $valid = [SyncService::DIRECTION_NC_TO_LR, SyncService::DIRECTION_LR_TO_NC, SyncService::DIRECTION_BOTH];
            if (!in_array($body['direction'], $valid, true)) {
                $errors[] = 'direction muss nc_to_lr, lr_to_nc oder bidirectional sein';
            }
        }

        return $errors;
    }

    private function getJsonBody(): array
    {
        $raw     = $this->request->getContent();
        $decoded = json_decode($raw ?: '{}', true);
        return is_array($decoded) ? $decoded : [];
    }

    private function getUserId(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }
}

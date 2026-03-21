<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Verwaltet Ordner-Zuordnungen und führt den bidirektionalen Sync zwischen
 * Nextcloud-JPEG-Bewertungen und Lightroom-XMP-Sidecars durch.
 *
 * Sync-Richtungen:
 *   nc_to_lr   — Nextcloud → Lightroom (schreibt .xmp neben .cr3)
 *   lr_to_nc   — Lightroom → Nextcloud (liest .xmp, aktualisiert NC-Tags + JPEG-XMP)
 *   bidirectional — beide Richtungen, Konflikt = neuerer mtime gewinnt
 *
 * Zuordnungen werden pro Benutzer in der NC-Config gespeichert.
 */
class SyncService
{
    private const CONFIG_KEY     = 'starrate_sync_mappings';
    private const APP_ID         = 'starrate';
    private const MAX_LOG_ENTRIES = 10;

    public const DIRECTION_NC_TO_LR = 'nc_to_lr';
    public const DIRECTION_LR_TO_NC = 'lr_to_nc';
    public const DIRECTION_BOTH     = 'bidirectional';

    public const STATUS_OK      = 'ok';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ERROR   = 'error';
    public const STATUS_NEVER   = 'never';

    public function __construct(
        private readonly IConfig         $config,
        private readonly IRootFolder     $rootFolder,
        private readonly TagService      $tagService,
        private readonly ExifService     $exifService,
        private readonly XmpService      $xmpService,
        private readonly LoggerInterface $logger,
    ) {}

    // ─── Zuordnungen verwalten ────────────────────────────────────────────────

    /**
     * Gibt alle Sync-Zuordnungen des Benutzers zurück.
     *
     * @return array<int, array{
     *     id: int,
     *     nc_path: string,
     *     local_path: string,
     *     direction: string,
     *     last_sync: int|null,
     *     status: string,
     *     log: array
     * }>
     */
    public function getMappings(string $userId): array
    {
        $raw = $this->config->getUserValue($userId, self::APP_ID, self::CONFIG_KEY, '[]');
        $mappings = json_decode($raw, true);
        return is_array($mappings) ? $mappings : [];
    }

    /**
     * Fügt eine neue Zuordnung hinzu.
     *
     * @return array  Die neue Zuordnung inkl. generierter ID
     */
    public function addMapping(string $userId, string $ncPath, string $localPath, string $direction = self::DIRECTION_BOTH): array
    {
        $this->validateDirection($direction);

        $mappings = $this->getMappings($userId);
        $newId    = $this->generateId($mappings);

        $mapping = [
            'id'         => $newId,
            'nc_path'    => rtrim($ncPath, '/'),
            'local_path' => rtrim($localPath, DIRECTORY_SEPARATOR),
            'direction'  => $direction,
            'last_sync'  => null,
            'status'     => self::STATUS_NEVER,
            'log'        => [],
        ];

        $mappings[] = $mapping;
        $this->saveMappings($userId, $mappings);

        $this->logger->info("StarRate: Sync-Zuordnung {$newId} erstellt für Benutzer {$userId}.");
        return $mapping;
    }

    /**
     * Aktualisiert eine bestehende Zuordnung.
     *
     * @param array{nc_path?: string, local_path?: string, direction?: string} $data
     * @throws \RuntimeException wenn ID nicht gefunden
     */
    public function updateMapping(string $userId, int $mappingId, array $data): array
    {
        $mappings = $this->getMappings($userId);
        $index    = $this->findMappingIndex($mappings, $mappingId);

        if (isset($data['direction'])) {
            $this->validateDirection($data['direction']);
        }

        if (isset($data['nc_path'])) {
            $mappings[$index]['nc_path'] = rtrim($data['nc_path'], '/');
        }
        if (isset($data['local_path'])) {
            $mappings[$index]['local_path'] = rtrim($data['local_path'], DIRECTORY_SEPARATOR);
        }
        if (isset($data['direction'])) {
            $mappings[$index]['direction'] = $data['direction'];
        }

        $this->saveMappings($userId, $mappings);
        return $mappings[$index];
    }

    /**
     * Löscht eine Zuordnung.
     *
     * @throws \RuntimeException wenn ID nicht gefunden
     */
    public function deleteMapping(string $userId, int $mappingId): void
    {
        $mappings = $this->getMappings($userId);
        $index    = $this->findMappingIndex($mappings, $mappingId);

        array_splice($mappings, $index, 1);
        $this->saveMappings($userId, $mappings);

        $this->logger->info("StarRate: Sync-Zuordnung {$mappingId} gelöscht.");
    }

    /**
     * Gibt eine einzelne Zuordnung zurück.
     *
     * @throws \RuntimeException wenn nicht gefunden
     */
    public function getMapping(string $userId, int $mappingId): array
    {
        $mappings = $this->getMappings($userId);
        $index    = $this->findMappingIndex($mappings, $mappingId);
        return $mappings[$index];
    }

    // ─── Sync ausführen ───────────────────────────────────────────────────────

    /**
     * Führt den Sync für eine Zuordnung aus.
     *
     * @return array{synced: int, skipped: int, errors: int, log: string[]}
     */
    public function runSync(string $userId, int $mappingId): array
    {
        $mappings = $this->getMappings($userId);
        $index    = $this->findMappingIndex($mappings, $mappingId);
        $mapping  = $mappings[$index];

        $result = [
            'synced'  => 0,
            'skipped' => 0,
            'errors'  => 0,
            'log'     => [],
        ];

        try {
            $ncFolder = $this->getNcFolder($userId, $mapping['nc_path']);

            switch ($mapping['direction']) {
                case self::DIRECTION_NC_TO_LR:
                    $this->syncNcToLr($ncFolder, $mapping['local_path'], $result);
                    break;

                case self::DIRECTION_LR_TO_NC:
                    $this->syncLrToNc($ncFolder, $mapping['local_path'], $userId, $result);
                    break;

                case self::DIRECTION_BOTH:
                    $this->syncBidirectional($ncFolder, $mapping['local_path'], $userId, $result);
                    break;
            }

            $mappings[$index]['status']    = $result['errors'] > 0 ? self::STATUS_ERROR : self::STATUS_OK;
            $mappings[$index]['last_sync'] = time();

        } catch (\Exception $e) {
            $result['errors']++;
            $result['log'][]           = 'FEHLER: ' . $e->getMessage();
            $mappings[$index]['status'] = self::STATUS_ERROR;
            $this->logger->error("StarRate: Sync-Fehler für Zuordnung {$mappingId}: " . $e->getMessage());
        }

        // Log-Einträge hinzufügen (maximal MAX_LOG_ENTRIES behalten)
        $timestamp = date('Y-m-d H:i:s');
        $newEntries = array_map(fn($l) => "[{$timestamp}] {$l}", $result['log']);
        $mappings[$index]['log'] = array_slice(
            array_merge($newEntries, $mappings[$index]['log'] ?? []),
            0,
            self::MAX_LOG_ENTRIES
        );

        $this->saveMappings($userId, $mappings);

        return $result;
    }

    // ─── NC → Lightroom ───────────────────────────────────────────────────────

    /**
     * Nextcloud → Lightroom: Liest JPEG-Metadaten aus NC und schreibt .xmp-Sidecars lokal.
     */
    private function syncNcToLr(Folder $ncFolder, string $localPath, array &$result): void
    {
        if (!is_dir($localPath)) {
            throw new \RuntimeException("Lokales Verzeichnis nicht gefunden: {$localPath}");
        }

        $nodes = $ncFolder->getDirectoryListing();

        foreach ($nodes as $node) {
            if (!($node instanceof File)) {
                continue;
            }

            $filename = $node->getName();
            if (!$this->xmpService->isJpegFile($filename)) {
                continue;
            }

            $baseName = $this->xmpService->getBaseName($filename);

            // Prüfe ob passendes RAW im lokalen Ordner existiert
            $localMatches = $this->xmpService->findMatchingLocalFiles($localPath, $baseName);
            $hasRaw = false;
            foreach ($localMatches as $match) {
                if ($this->xmpService->isRawFile($match)) {
                    $hasRaw = true;
                    break;
                }
            }

            if (!$hasRaw) {
                $result['skipped']++;
                continue;
            }

            try {
                // Metadaten aus dem JPEG lesen
                $meta = $this->exifService->readMetadata($node);

                // XMP-Sidecar lokal schreiben
                $sidecarPath = $this->xmpService->writeSidecarLocal(
                    $localPath . DIRECTORY_SEPARATOR . $baseName . '.cr3', // Pfad-Konvention
                    $meta['rating'],
                    $meta['label']
                );

                $result['synced']++;
                $result['log'][] = "NC→LR: {$filename} → " . basename($sidecarPath)
                    . " (★{$meta['rating']}" . ($meta['label'] ? ", {$meta['label']}" : '') . ')';

            } catch (\Exception $e) {
                $result['errors']++;
                $result['log'][] = "FEHLER NC→LR {$filename}: " . $e->getMessage();
            }
        }
    }

    // ─── Lightroom → NC ───────────────────────────────────────────────────────

    /**
     * Lightroom → Nextcloud: Liest .xmp-Sidecars aus lokalem Ordner,
     * aktualisiert NC-Tags und JPEG-Metadaten.
     */
    private function syncLrToNc(Folder $ncFolder, string $localPath, string $userId, array &$result): void
    {
        if (!is_dir($localPath)) {
            throw new \RuntimeException("Lokales Verzeichnis nicht gefunden: {$localPath}");
        }

        $localFiles = scandir($localPath);
        if ($localFiles === false) {
            return;
        }

        foreach ($localFiles as $localFile) {
            if (strtolower(pathinfo($localFile, PATHINFO_EXTENSION)) !== 'xmp') {
                continue;
            }

            $baseName    = pathinfo($localFile, PATHINFO_FILENAME);
            $sidecarPath = $localPath . DIRECTORY_SEPARATOR . $localFile;
            $xmpData     = $this->xmpService->readSidecarLocal($sidecarPath);

            if ($xmpData === null) {
                $result['skipped']++;
                continue;
            }

            // Passendes JPEG in Nextcloud suchen
            $ncJpeg = $this->findNcJpeg($ncFolder, $baseName);
            if ($ncJpeg === null) {
                $result['skipped']++;
                $result['log'][] = "LR→NC übersprungen: kein JPEG für '{$baseName}' in NC";
                continue;
            }

            try {
                $fileId = (string) $ncJpeg->getId();

                // NC-Tags aktualisieren
                $this->tagService->setMetadata($fileId, [
                    'rating' => $xmpData['rating'],
                    'color'  => $xmpData['label'],
                ]);

                // JPEG-XMP aktualisieren
                $this->exifService->writeMetadata($ncJpeg, $xmpData['rating'], $xmpData['label']);

                $result['synced']++;
                $result['log'][] = "LR→NC: {$localFile} → {$ncJpeg->getName()}"
                    . " (★{$xmpData['rating']}" . ($xmpData['label'] ? ", {$xmpData['label']}" : '') . ')';

            } catch (\Exception $e) {
                $result['errors']++;
                $result['log'][] = "FEHLER LR→NC {$localFile}: " . $e->getMessage();
            }
        }
    }

    // ─── Bidirektional ────────────────────────────────────────────────────────

    /**
     * Bidirektionaler Sync: Vergleicht mtime, der neuere Eintrag gewinnt.
     */
    private function syncBidirectional(Folder $ncFolder, string $localPath, string $userId, array &$result): void
    {
        if (!is_dir($localPath)) {
            throw new \RuntimeException("Lokales Verzeichnis nicht gefunden: {$localPath}");
        }

        // Alle JPEG-Dateien in NC sammeln
        $ncFiles = [];
        foreach ($ncFolder->getDirectoryListing() as $node) {
            if ($node instanceof File && $this->xmpService->isJpegFile($node->getName())) {
                $baseName           = $this->xmpService->getBaseName($node->getName());
                $ncFiles[$baseName] = $node;
            }
        }

        // Alle XMP-Sidecars lokal sammeln
        $localSidecars = [];
        $files = scandir($localPath);
        if ($files !== false) {
            foreach ($files as $f) {
                if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'xmp') {
                    $base                   = pathinfo($f, PATHINFO_FILENAME);
                    $localSidecars[$base]   = $localPath . DIRECTORY_SEPARATOR . $f;
                }
            }
        }

        // Vereinigung beider Basis-Namen
        $allBases = array_unique(array_merge(array_keys($ncFiles), array_keys($localSidecars)));

        foreach ($allBases as $baseName) {
            $ncNode       = $ncFiles[$baseName] ?? null;
            $sidecarPath  = $localSidecars[$baseName] ?? null;

            try {
                $ncMtime    = $ncNode    ? $ncNode->getMtime() : 0;
                $localMtime = $sidecarPath ? (int) filemtime($sidecarPath) : 0;

                if ($ncMtime >= $localMtime && $ncNode !== null) {
                    // NC ist neuer → NC → LR
                    $this->pushNcToLocal($ncNode, $localPath, $baseName, $result);
                } elseif ($localMtime > $ncMtime && $sidecarPath !== null && $ncNode !== null) {
                    // Lokal ist neuer → LR → NC
                    $this->pushLocalToNc($sidecarPath, $ncNode, $result);
                } else {
                    $result['skipped']++;
                }
            } catch (\Exception $e) {
                $result['errors']++;
                $result['log'][] = "FEHLER Bidirektional {$baseName}: " . $e->getMessage();
            }
        }
    }

    /**
     * Schreibt NC-Metadaten in lokale Sidecar (NC gewinnt im Konflikt).
     */
    private function pushNcToLocal(File $ncNode, string $localPath, string $baseName, array &$result): void
    {
        $meta = $this->exifService->readMetadata($ncNode);

        // Lokale RAW-Datei suchen um Pfad für Sidecar zu ermitteln
        $rawCandidates = $this->xmpService->findMatchingLocalFiles($localPath, $baseName);
        $rawPath       = null;
        foreach ($rawCandidates as $c) {
            if ($this->xmpService->isRawFile($c)) {
                $rawPath = $c;
                break;
            }
        }

        // Kein RAW → Sidecar trotzdem schreiben, mit konventionellem Pfad
        $targetPath = $rawPath ?? ($localPath . DIRECTORY_SEPARATOR . $baseName . '.cr3');

        $sidecarPath = $this->xmpService->writeSidecarLocal($targetPath, $meta['rating'], $meta['label']);

        $result['synced']++;
        $result['log'][] = "NC→LR (Konflikt NC neuer): {$ncNode->getName()} → " . basename($sidecarPath);
    }

    /**
     * Schreibt lokale Sidecar in NC-JPEG (Lokal gewinnt im Konflikt).
     */
    private function pushLocalToNc(string $sidecarPath, File $ncNode, array &$result): void
    {
        $xmpData = $this->xmpService->readSidecarLocal($sidecarPath);
        if ($xmpData === null) {
            $result['skipped']++;
            return;
        }

        $fileId = (string) $ncNode->getId();
        $this->tagService->setMetadata($fileId, [
            'rating' => $xmpData['rating'],
            'color'  => $xmpData['label'],
        ]);
        $this->exifService->writeMetadata($ncNode, $xmpData['rating'], $xmpData['label']);

        $result['synced']++;
        $result['log'][] = "LR→NC (Konflikt LR neuer): " . basename($sidecarPath) . " → {$ncNode->getName()}";
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    /**
     * Sucht ein JPEG in einem NC-Ordner anhand des Basis-Namens (case-insensitive).
     */
    private function findNcJpeg(Folder $folder, string $baseName): ?File
    {
        foreach ($folder->getDirectoryListing() as $node) {
            if (!($node instanceof File)) {
                continue;
            }
            if ($this->xmpService->isJpegFile($node->getName())
                && strcasecmp($this->xmpService->getBaseName($node->getName()), $baseName) === 0
            ) {
                return $node;
            }
        }
        return null;
    }

    /**
     * Holt den NC-Ordner für den angegebenen Benutzer und Pfad.
     */
    private function getNcFolder(string $userId, string $ncPath): Folder
    {
        $userFolder = $this->rootFolder->getUserFolder($userId);

        if ($ncPath === '' || $ncPath === '/') {
            return $userFolder;
        }

        $node = $userFolder->get(ltrim($ncPath, '/'));
        if (!($node instanceof Folder)) {
            throw new \RuntimeException("'{$ncPath}' ist kein Ordner in Nextcloud.");
        }

        return $node;
    }

    /**
     * Findet den Array-Index einer Zuordnung anhand der ID.
     *
     * @throws \RuntimeException wenn nicht gefunden
     */
    private function findMappingIndex(array $mappings, int $id): int
    {
        foreach ($mappings as $i => $m) {
            if ((int) $m['id'] === $id) {
                return $i;
            }
        }
        throw new \RuntimeException("Sync-Zuordnung mit ID {$id} nicht gefunden.");
    }

    private function saveMappings(string $userId, array $mappings): void
    {
        $this->config->setUserValue($userId, self::APP_ID, self::CONFIG_KEY, json_encode($mappings));
    }

    private function generateId(array $mappings): int
    {
        if (empty($mappings)) {
            return 1;
        }
        return max(array_column($mappings, 'id')) + 1;
    }

    private function validateDirection(string $direction): void
    {
        $valid = [self::DIRECTION_NC_TO_LR, self::DIRECTION_LR_TO_NC, self::DIRECTION_BOTH];
        if (!in_array($direction, $valid, true)) {
            throw new \InvalidArgumentException(
                "Ungültige Sync-Richtung: {$direction}. Erlaubt: " . implode(', ', $valid)
            );
        }
    }
}

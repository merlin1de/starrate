<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Verwaltet Sync-Zuordnungen zwischen Nextcloud-Ordnern und lokalen Lightroom-Ordnern.
 *
 * Der eigentliche Sync wird vom PowerShell-Script scripts/sync-lr.ps1 auf dem
 * lokalen PC ausgeführt. Dieses Modul speichert nur die Mapping-Konfiguration
 * und empfängt Sync-Ergebnisse vom Script zur Anzeige im SyncPanel.
 *
 * @see scripts/sync-lr.ps1
 */
class SyncService
{
    private const CONFIG_KEY      = 'starrate_sync_mappings';
    private const APP_ID          = 'starrate';
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
        $raw      = $this->config->getUserValue($userId, self::APP_ID, self::CONFIG_KEY, '[]');
        $mappings = json_decode($raw, true);
        return is_array($mappings) ? $mappings : [];
    }

    /**
     * Fügt eine neue Zuordnung hinzu.
     *
     * @return array Die neue Zuordnung inkl. generierter ID
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

    // ─── Sync-Ergebnis empfangen ──────────────────────────────────────────────

    /**
     * Empfängt das Ergebnis eines externen Syncs (scripts/sync-lr.ps1)
     * und aktualisiert Status + Log der Zuordnung.
     *
     * Wird von POST /api/sync/run/{id} aufgerufen, nachdem das Script
     * den Sync lokal ausgeführt hat.
     *
     * @param array{synced: int, skipped?: int, errors: int, log: string[]} $result
     */
    public function recordResult(string $userId, int $mappingId, array $result): void
    {
        $mappings = $this->getMappings($userId);
        $index    = $this->findMappingIndex($mappings, $mappingId);

        $mappings[$index]['status']    = ($result['errors'] ?? 0) > 0 ? self::STATUS_ERROR : self::STATUS_OK;
        $mappings[$index]['last_sync'] = time();

        $timestamp  = date('Y-m-d H:i:s');
        $newEntries = array_map(fn($l) => "[{$timestamp}] {$l}", $result['log'] ?? []);

        $mappings[$index]['log'] = array_slice(
            array_merge($newEntries, $mappings[$index]['log'] ?? []),
            0,
            self::MAX_LOG_ENTRIES
        );

        $this->saveMappings($userId, $mappings);
        $this->logger->info("StarRate: Sync-Ergebnis für Zuordnung {$mappingId} empfangen: {$result['synced']} synchronisiert, {$result['errors']} Fehler.");
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

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

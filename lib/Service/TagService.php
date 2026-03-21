<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\IDBConnection;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Verwaltet Bewertungen und Farbmarkierungen als Nextcloud Collaborative Tags.
 *
 * Tag-Namensschema:
 *   starrate:rating:3        → 3 Sterne
 *   starrate:color:Red       → Rote Farbmarkierung
 *   starrate:pick:pick       → Pick (P)
 *   starrate:pick:reject     → Reject (X)
 */
class TagService
{
    private const TAG_PREFIX_RATING = 'starrate:rating:';
    private const TAG_PREFIX_COLOR  = 'starrate:color:';
    private const TAG_PREFIX_PICK   = 'starrate:pick:';
    private const OBJECT_TYPE       = 'files';

    public const VALID_RATINGS = [0, 1, 2, 3, 4, 5];
    public const VALID_COLORS  = ['Red', 'Yellow', 'Green', 'Blue', 'Purple'];
    public const VALID_PICKS   = ['pick', 'reject', 'none'];

    public function __construct(
        private readonly ISystemTagManager      $tagManager,
        private readonly ISystemTagObjectMapper $tagMapper,
        private readonly IDBConnection          $db,
        private readonly LoggerInterface        $logger,
    ) {}

    // ─── Bewertung (Sterne) ───────────────────────────────────────────────────

    /**
     * Setzt die Sternebewertung für eine Datei.
     * Entfernt zuerst alle vorhandenen Rating-Tags, dann setzt den neuen.
     */
    public function setRating(string $fileId, int $rating): void
    {
        if (!in_array($rating, self::VALID_RATINGS, true)) {
            throw new \InvalidArgumentException("Ungültige Bewertung: {$rating}. Erlaubt: 0–5.");
        }

        $this->removeTagsByPrefix($fileId, self::TAG_PREFIX_RATING);

        // Rating 0 = keine Bewertung → kein Tag setzen
        if ($rating > 0) {
            $tag = $this->getOrCreateTag(self::TAG_PREFIX_RATING . $rating);
            $this->tagMapper->assignTags($fileId, self::OBJECT_TYPE, [$tag->getId()]);
        }

        $this->logger->debug("StarRate: Rating {$rating} für Datei {$fileId} gesetzt.");
    }

    /**
     * Liest die aktuelle Sternebewertung einer Datei (0 = keine).
     */
    public function getRating(string $fileId): int
    {
        for ($i = 5; $i >= 1; $i--) {
            $tagName = self::TAG_PREFIX_RATING . $i;
            if ($this->fileHasTag($fileId, $tagName)) {
                return $i;
            }
        }
        return 0;
    }

    // ─── Farbmarkierung ───────────────────────────────────────────────────────

    /**
     * Setzt die Farbmarkierung für eine Datei.
     * null = Farbmarkierung entfernen.
     */
    public function setColor(string $fileId, ?string $color): void
    {
        if ($color !== null && !in_array($color, self::VALID_COLORS, true)) {
            throw new \InvalidArgumentException(
                "Ungültige Farbe: {$color}. Erlaubt: " . implode(', ', self::VALID_COLORS)
            );
        }

        $this->removeTagsByPrefix($fileId, self::TAG_PREFIX_COLOR);

        if ($color !== null) {
            $tag = $this->getOrCreateTag(self::TAG_PREFIX_COLOR . $color);
            $this->tagMapper->assignTags($fileId, self::OBJECT_TYPE, [$tag->getId()]);
        }

        $this->logger->debug("StarRate: Farbe " . ($color ?? 'keine') . " für Datei {$fileId} gesetzt.");
    }

    /**
     * Liest die aktuelle Farbmarkierung einer Datei (null = keine).
     */
    public function getColor(string $fileId): ?string
    {
        foreach (self::VALID_COLORS as $color) {
            if ($this->fileHasTag($fileId, self::TAG_PREFIX_COLOR . $color)) {
                return $color;
            }
        }
        return null;
    }

    // ─── Pick / Reject ────────────────────────────────────────────────────────

    /**
     * Setzt den Pick-Status: 'pick', 'reject' oder 'none'.
     */
    public function setPick(string $fileId, string $pick): void
    {
        if (!in_array($pick, self::VALID_PICKS, true)) {
            throw new \InvalidArgumentException("Ungültiger Pick-Status: {$pick}.");
        }

        $this->removeTagsByPrefix($fileId, self::TAG_PREFIX_PICK);

        if ($pick !== 'none') {
            $tag = $this->getOrCreateTag(self::TAG_PREFIX_PICK . $pick);
            $this->tagMapper->assignTags($fileId, self::OBJECT_TYPE, [$tag->getId()]);
        }
    }

    /**
     * Liest den Pick-Status einer Datei ('pick', 'reject' oder 'none').
     */
    public function getPick(string $fileId): string
    {
        if ($this->fileHasTag($fileId, self::TAG_PREFIX_PICK . 'pick')) {
            return 'pick';
        }
        if ($this->fileHasTag($fileId, self::TAG_PREFIX_PICK . 'reject')) {
            return 'reject';
        }
        return 'none';
    }

    // ─── Kombiniert: alle Metadaten auf einmal ────────────────────────────────

    /**
     * Setzt Rating + Color + Pick in einem Aufruf (atomarer Batch).
     *
     * @param array{rating?: int, color?: string|null, pick?: string} $data
     */
    public function setMetadata(string $fileId, array $data): void
    {
        if (isset($data['rating'])) {
            $this->setRating($fileId, (int) $data['rating']);
        }
        if (array_key_exists('color', $data)) {
            $this->setColor($fileId, $data['color']);
        }
        if (isset($data['pick'])) {
            $this->setPick($fileId, $data['pick']);
        }
    }

    /**
     * Liest alle Metadaten einer Datei.
     *
     * @return array{rating: int, color: string|null, pick: string}
     */
    public function getMetadata(string $fileId): array
    {
        return [
            'rating' => $this->getRating($fileId),
            'color'  => $this->getColor($fileId),
            'pick'   => $this->getPick($fileId),
        ];
    }

    /**
     * Liest Metadaten für mehrere Dateien auf einmal (effizient, eine DB-Abfrage).
     *
     * @param  string[]  $fileIds
     * @return array<string, array{rating: int, color: string|null, pick: string}>
     */
    public function getMetadataBatch(array $fileIds): array
    {
        if (empty($fileIds)) {
            return [];
        }

        // Alle StarRate-Tags für die angefragten Dateien laden
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $sql = "
            SELECT stom.objectid, st.name
            FROM   oc_systemtag_object_mapping stom
            JOIN   oc_systemtag st ON st.id = stom.systemtagid
            WHERE  stom.objecttype = ?
              AND  stom.objectid IN ({$placeholders})
              AND  st.name LIKE 'starrate:%'
        ";

        $params = array_merge([self::OBJECT_TYPE], $fileIds);
        $result = $this->db->executeQuery($sql, $params);

        $rows = $result->fetchAll();

        // Initialisiere alle Dateien mit Default-Werten
        $metadata = [];
        foreach ($fileIds as $id) {
            $metadata[$id] = ['rating' => 0, 'color' => null, 'pick' => 'none'];
        }

        foreach ($rows as $row) {
            $fileId  = (string) $row['objectid'];
            $tagName = $row['name'];

            if (str_starts_with($tagName, self::TAG_PREFIX_RATING)) {
                $value = (int) substr($tagName, strlen(self::TAG_PREFIX_RATING));
                if (in_array($value, self::VALID_RATINGS, true)) {
                    $metadata[$fileId]['rating'] = $value;
                }
            } elseif (str_starts_with($tagName, self::TAG_PREFIX_COLOR)) {
                $value = substr($tagName, strlen(self::TAG_PREFIX_COLOR));
                if (in_array($value, self::VALID_COLORS, true)) {
                    $metadata[$fileId]['color'] = $value;
                }
            } elseif (str_starts_with($tagName, self::TAG_PREFIX_PICK)) {
                $value = substr($tagName, strlen(self::TAG_PREFIX_PICK));
                if (in_array($value, self::VALID_PICKS, true)) {
                    $metadata[$fileId]['pick'] = $value;
                }
            }
        }

        return $metadata;
    }

    /**
     * Alle StarRate-Tags einer Datei entfernen (z. B. bei Datei-Löschung).
     */
    public function clearAll(string $fileId): void
    {
        $this->removeTagsByPrefix($fileId, 'starrate:');
    }

    // ─── Filter-Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Gibt alle fileIds zurück, die eine Bewertung ≥ $minRating haben.
     *
     * @param  string[] $fileIds  Menge der zu prüfenden Dateien
     * @return string[]
     */
    public function filterByMinRating(array $fileIds, int $minRating): array
    {
        if (empty($fileIds) || $minRating <= 0) {
            return $fileIds;
        }

        $batch = $this->getMetadataBatch($fileIds);
        return array_values(array_filter(
            $fileIds,
            fn($id) => ($batch[$id]['rating'] ?? 0) >= $minRating
        ));
    }

    /**
     * Gibt alle fileIds zurück, die exakt $rating Sterne haben.
     *
     * @param  string[] $fileIds
     * @return string[]
     */
    public function filterByRating(array $fileIds, int $rating): array
    {
        $batch = $this->getMetadataBatch($fileIds);
        return array_values(array_filter(
            $fileIds,
            fn($id) => ($batch[$id]['rating'] ?? 0) === $rating
        ));
    }

    /**
     * Gibt alle fileIds zurück, die die gegebene Farbe haben.
     *
     * @param  string[] $fileIds
     * @return string[]
     */
    public function filterByColor(array $fileIds, string $color): array
    {
        $batch = $this->getMetadataBatch($fileIds);
        return array_values(array_filter(
            $fileIds,
            fn($id) => ($batch[$id]['color'] ?? null) === $color
        ));
    }

    // ─── Private Hilfsmethoden ────────────────────────────────────────────────

    /**
     * Gibt einen existierenden Tag zurück oder erstellt ihn (nicht sichtbar, nicht zuweisbar).
     */
    private function getOrCreateTag(string $name): \OCP\SystemTag\ISystemTag
    {
        try {
            $tags = $this->tagManager->getAllTags(null, $name);
            foreach ($tags as $tag) {
                if ($tag->getName() === $name) {
                    return $tag;
                }
            }
        } catch (\Exception) {
            // Tag nicht gefunden → neu anlegen
        }

        return $this->tagManager->createTag($name, false, false);
    }

    /**
     * Prüft ob eine Datei einen bestimmten Tag (nach Name) hat.
     */
    private function fileHasTag(string $fileId, string $tagName): bool
    {
        try {
            $tags = $this->tagManager->getAllTags(null, $tagName);
            foreach ($tags as $tag) {
                if ($tag->getName() === $tagName) {
                    return $this->tagMapper->haveTag([$fileId], self::OBJECT_TYPE, $tag->getId());
                }
            }
        } catch (\Exception) {
            // ignore
        }
        return false;
    }

    /**
     * Entfernt alle Tags mit dem gegebenen Präfix von einer Datei.
     */
    private function removeTagsByPrefix(string $fileId, string $prefix): void
    {
        try {
            $allTags = $this->tagMapper->getTagIdsForObjects([$fileId], self::OBJECT_TYPE);
            $fileTagIds = $allTags[$fileId] ?? [];

            if (empty($fileTagIds)) {
                return;
            }

            $tags = $this->tagManager->getTagsByIds($fileTagIds);
            $toRemove = [];

            foreach ($tags as $tag) {
                if (str_starts_with($tag->getName(), $prefix)) {
                    $toRemove[] = $tag->getId();
                }
            }

            if (!empty($toRemove)) {
                $this->tagMapper->unassignTags($fileId, self::OBJECT_TYPE, $toRemove);
            }
        } catch (TagNotFoundException $e) {
            $this->logger->debug("StarRate: Tag nicht gefunden beim Entfernen: " . $e->getMessage());
        } catch (\Exception $e) {
            $this->logger->warning("StarRate: Fehler beim Entfernen von Tags: " . $e->getMessage());
        }
    }
}

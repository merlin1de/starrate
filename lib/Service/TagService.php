<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
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

    // ─── Metadaten (Rating + Color + Pick) ────────────────────────────────────

    /**
     * Setzt Rating + Color + Pick in einem Aufruf.
     * Verwendet direkte SQL-Queries für systemtag_object_mapping, damit die Methode auch
     * ohne authentifizierten Nutzer funktioniert (z. B. bei Gast-Bewertungen via PublicPage).
     * ISystemTagObjectMapper::assignTags prüft ab NC 32 die User-Berechtigung und wirft
     * TagNotAllowedException() (leere Message) wenn kein User eingeloggt ist.
     *
     * @param array{rating?: int, color?: string|null, pick?: string} $data
     */
    public function setMetadata(string $fileId, array $data): void
    {
        // Validierung vorab
        if (array_key_exists('rating', $data) && $data['rating'] !== null
            && !in_array((int) $data['rating'], self::VALID_RATINGS, true)) {
            throw new \InvalidArgumentException("Invalid rating: {$data['rating']}. Allowed: 0–5.");
        }
        if (array_key_exists('color', $data) && $data['color'] !== null
            && !in_array($data['color'], self::VALID_COLORS, true)) {
            throw new \InvalidArgumentException(
                "Invalid color: {$data['color']}. Allowed: " . implode(', ', self::VALID_COLORS)
            );
        }
        if (isset($data['pick']) && !in_array($data['pick'], self::VALID_PICKS, true)) {
            throw new \InvalidArgumentException("Invalid pick status: {$data['pick']}.");
        }

        // 1. Bestehende StarRate-Tags der Datei per direktem SQL ermitteln
        //    (kein ISystemTagObjectMapper → kein User-Permission-Check)
        $prefixes = [];
        if (array_key_exists('rating', $data)) $prefixes[] = self::TAG_PREFIX_RATING;
        if (array_key_exists('color', $data))  $prefixes[] = self::TAG_PREFIX_COLOR;
        if (isset($data['pick']))              $prefixes[] = self::TAG_PREFIX_PICK;

        $toRemove = [];
        if (!empty($prefixes)) {
            try {
                $qb = $this->db->getQueryBuilder();
                $result = $qb->select('stom.systemtagid', 'st.name')
                    ->from('systemtag_object_mapping', 'stom')
                    ->innerJoin('stom', 'systemtag', 'st', $qb->expr()->eq('st.id', 'stom.systemtagid'))
                    ->where($qb->expr()->eq('stom.objectid', $qb->createNamedParameter($fileId)))
                    ->andWhere($qb->expr()->eq('stom.objecttype', $qb->createNamedParameter(self::OBJECT_TYPE)))
                    ->andWhere($qb->expr()->like('st.name', $qb->createNamedParameter('starrate:%')))
                    ->executeQuery();

                while ($row = $result->fetch()) {
                    foreach ($prefixes as $prefix) {
                        if (str_starts_with($row['name'], $prefix)) {
                            $toRemove[] = (int) $row['systemtagid'];
                            break;
                        }
                    }
                }
                $result->closeCursor();
            } catch (\Exception $e) {
                $this->logger->warning("StarRate: failed to read current tags for {$fileId}: " . $e->getMessage());
            }
        }

        // 2. Alte Tag-Zuordnungen per direktem SQL entfernen
        if (!empty($toRemove)) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('systemtag_object_mapping')
                ->where($qb->expr()->eq('objectid', $qb->createNamedParameter($fileId)))
                ->andWhere($qb->expr()->eq('objecttype', $qb->createNamedParameter(self::OBJECT_TYPE)))
                ->andWhere($qb->expr()->in('systemtagid', $qb->createNamedParameter($toRemove, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeStatement();
        }

        // 3. Neue Werte per direktem SQL-Insert setzen
        //    getOrCreateTagDirect statt getOrCreateTag: ISystemTagManager::createTag
        //    wirft ab NC 32 TagCreationForbiddenException im unauthentifizierten Context.
        if (array_key_exists('rating', $data) && (int) $data['rating'] > 0) {
            $tagId = $this->getOrCreateTagDirect(self::TAG_PREFIX_RATING . (int) $data['rating']);
            $this->assignTagDirect($fileId, $tagId);
        }
        if (array_key_exists('color', $data) && $data['color'] !== null) {
            $tagId = $this->getOrCreateTagDirect(self::TAG_PREFIX_COLOR . $data['color']);
            $this->assignTagDirect($fileId, $tagId);
        }
        if (isset($data['pick']) && $data['pick'] !== 'none') {
            $tagId = $this->getOrCreateTagDirect(self::TAG_PREFIX_PICK . $data['pick']);
            $this->assignTagDirect($fileId, $tagId);
        }
    }

    /**
     * Gibt die Tag-ID zurück; erstellt den Tag per direktem SQL falls er nicht existiert.
     * Kein ISystemTagManager-Aufruf → kein User-Permission-Check (funktioniert in Guest-Context).
     * Tags werden als unsichtbar + nicht-zuweisbar angelegt (wie createTag($name, false, false)).
     */
    private function getOrCreateTagDirect(string $name): string
    {
        // Suche in der systemtag-Tabelle
        $qb     = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from('systemtag')
            ->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
            ->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row !== false) {
            return (string) $row['id'];
        }

        // Tag noch nicht vorhanden → anlegen
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('systemtag')
                ->values([
                    'name'       => $qb->createNamedParameter($name),
                    'visibility' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    'editable'   => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                ])
                ->executeStatement();
        } catch (\Exception) {
            // Duplicate Key (Race Condition) → ignorieren, unten nochmal lesen
        }

        // Nach INSERT oder Race-Condition: ID aus DB lesen
        $qb     = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from('systemtag')
            ->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
            ->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            throw new \RuntimeException("Tag '{$name}' konnte nicht erstellt oder gefunden werden.");
        }

        return (string) $row['id'];
    }

    /**
     * Weist einem File einen Tag direkt per SQL zu (kein User-Permission-Check).
     * Ignoriert Duplicate-Key-Fehler (bereits zugewiesen → kein Problem).
     */
    private function assignTagDirect(string $fileId, string $tagId): void
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('systemtag_object_mapping')
                ->values([
                    'systemtagid' => $qb->createNamedParameter((int) $tagId, IQueryBuilder::PARAM_INT),
                    'objectid'    => $qb->createNamedParameter($fileId),
                    'objecttype'  => $qb->createNamedParameter(self::OBJECT_TYPE),
                ])
                ->executeStatement();
        } catch (\Exception) {
            // Duplicate Entry (Tag bereits zugewiesen) → ignorieren
        }
    }

    /**
     * Liest alle Metadaten einer Datei (nutzt getMetadataBatch: 1 SQL-Abfrage).
     *
     * @return array{rating: int, color: string|null, pick: string}
     */
    public function getMetadata(string $fileId): array
    {
        $batch = $this->getMetadataBatch([$fileId]);
        return $batch[$fileId] ?? ['rating' => 0, 'color' => null, 'pick' => 'none'];
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

        // Alle StarRate-Tags für die angefragten Dateien laden (QueryBuilder für korrekten Tabellen-Prefix)
        $qb = $this->db->getQueryBuilder();
        $qb->select('stom.objectid', 'st.name')
            ->from('systemtag_object_mapping', 'stom')
            ->innerJoin('stom', 'systemtag', 'st', $qb->expr()->eq('st.id', 'stom.systemtagid'))
            ->where($qb->expr()->eq('stom.objecttype', $qb->createNamedParameter(self::OBJECT_TYPE)))
            ->andWhere($qb->expr()->in('stom.objectid', $qb->createNamedParameter($fileIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->like('st.name', $qb->createNamedParameter('starrate:%')));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

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
            $this->logger->debug("StarRate: tag not found during removal: " . $e->getMessage());
        } catch (\Exception $e) {
            $this->logger->warning("StarRate: failed to unassign tags: " . $e->getMessage());
        }
    }
}

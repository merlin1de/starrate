<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview as IPreviewManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Verwaltet öffentliche Freigabe-Links für Gäste (Modelle, Kunden).
 *
 * Shares werden in der NC-Config gespeichert.
 * Gast-Bewertungen werden als echte NC Collaborative Tags gespeichert (via TagService).
 * Ein Log pro Share protokolliert wer wann was bewertet hat.
 */
class ShareService
{
    public const PERM_VIEW = 'view';
    public const PERM_RATE = 'rate';

    private const APP_ID               = 'starrate';
    private const CONFIG_SHARES        = 'starrate_shares';
    private const CONFIG_LOG           = 'starrate_guest_log';  // key: CONFIG_LOG_<token>
    private const TOKEN_LENGTH         = 24;
    private const GUEST_LOG_MAX_ENTRIES = 500;

    // Unterstützte MIME-Typen für Gast-Galerie
    private const GUEST_MIME = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly IConfig         $config,
        private readonly IRootFolder     $rootFolder,
        private readonly IPreviewManager $previewManager,
        private readonly ISecureRandom   $secureRandom,
        private readonly TagService      $tagService,
        private readonly LoggerInterface $logger,
        private readonly IDBConnection $db,
    ) {}

    // ─── Share erstellen / verwalten ─────────────────────────────────────────

    /**
     * Erstellt einen neuen Freigabe-Link.
     *
     * @return array{token: string, nc_path: string, owner_id: string, ...}
     */
    public function createShare(
        string  $ownerId,
        string  $ncPath,
        ?string $password,
        ?int    $expiresAt,
        int     $minRating,
        string  $permissions,
        ?string $guestName = null,
        bool    $allowPick = false,
        bool    $allowExport = false,
        bool    $allowComment = false,
    ): array {
        $this->validatePermissions($permissions);

        $token = $this->secureRandom->generate(
            self::TOKEN_LENGTH,
            ISecureRandom::CHAR_ALPHANUMERIC
        );

        $share = [
            'token'         => $token,
            'owner_id'      => $ownerId,
            'nc_path'       => rtrim($ncPath, '/'),
            'password_hash' => $password ? password_hash($password, PASSWORD_BCRYPT) : null,
            'expires_at'    => $expiresAt,
            'min_rating'    => max(0, min(5, $minRating)),
            'permissions'   => $permissions,
            'guest_name'    => $guestName ? trim($guestName) : null,
            'allow_pick'    => $allowPick,
            'allow_export'  => $allowExport,
            'allow_comment' => $allowComment,
            'created_at'    => time(),
            'active'        => true,
        ];

        $allShares           = $this->loadAllShares($ownerId);
        $allShares[$token]   = $share;
        $this->saveShares($ownerId, $allShares);

        $safe                 = $share;
        $safe['has_password'] = $password !== null;
        unset($safe['password_hash']);

        $this->logger->info("StarRate: share {$token} created by {$ownerId} for {$ncPath}.");
        return $safe;
    }

    /**
     * Lädt einen Share anhand des Tokens (sucht in allen Benutzern).
     */
    public function getShare(string $token): ?array
    {
        $qb     = \OC::$server->getDatabaseConnection()->getQueryBuilder();
        $result = $qb->select('userid', 'configvalue')
            ->from('preferences')
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter(self::APP_ID)))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(self::CONFIG_SHARES)))
            ->executeQuery();

        while ($row = $result->fetch()) {
            $shares = json_decode($row['configvalue'], true);
            if (is_array($shares) && isset($shares[$token])) {
                $share                = $shares[$token];
                $share['has_password'] = !empty($share['password_hash']);
                return $share;
            }
        }

        return null;
    }

    /**
     * Gibt alle Shares eines Benutzers zurück (ohne Passwort-Hash).
     *
     * @return array[]
     */
    public function getSharesByOwner(string $ownerId): array
    {
        $shares = $this->loadAllShares($ownerId);
        return array_values(array_map(function (array $s): array {
            $s['has_password'] = !empty($s['password_hash']);
            unset($s['password_hash']);
            return $s;
        }, $shares));
    }

    /**
     * Aktualisiert einen bestehenden Share.
     */
    public function updateShare(string $token, array $data): array
    {
        $share   = $this->getShare($token);
        if ($share === null) {
            throw new \RuntimeException("Share {$token} nicht gefunden.");
        }

        $ownerId = $share['owner_id'];
        $all     = $this->loadAllShares($ownerId);

        if (array_key_exists('password', $data)) {
            $all[$token]['password_hash'] = $data['password']
                ? password_hash($data['password'], PASSWORD_BCRYPT)
                : null;
        }
        if (isset($data['expires_at'])) {
            $all[$token]['expires_at'] = (int) $data['expires_at'];
        }
        if (isset($data['min_rating'])) {
            $all[$token]['min_rating'] = max(0, min(5, (int) $data['min_rating']));
        }
        if (isset($data['permissions'])) {
            $this->validatePermissions($data['permissions']);
            $all[$token]['permissions'] = $data['permissions'];
        }
        if (isset($data['active'])) {
            $all[$token]['active'] = (bool) $data['active'];
        }
        if (isset($data['allow_pick'])) {
            $all[$token]['allow_pick'] = (bool) $data['allow_pick'];
        }
        if (isset($data['allow_export'])) {
            $all[$token]['allow_export'] = (bool) $data['allow_export'];
        }
        if (isset($data['allow_comment'])) {
            $all[$token]['allow_comment'] = (bool) $data['allow_comment'];
        }

        $this->saveShares($ownerId, $all);

        $updated               = $all[$token];
        $updated['has_password'] = !empty($updated['password_hash']);
        unset($updated['password_hash']);
        return $updated;
    }

    /**
     * Löscht einen Share und sein Log.
     */
    public function deleteShare(string $token): void
    {
        $share = $this->getShare($token);
        if ($share === null) {
            return;
        }

        $ownerId = $share['owner_id'];
        $all     = $this->loadAllShares($ownerId);
        unset($all[$token]);
        $this->saveShares($ownerId, $all);

        // Log mit löschen
        $this->config->deleteUserValue($ownerId, self::APP_ID, self::CONFIG_LOG . '_' . $token);

        $this->logger->info("StarRate: share {$token} deleted.");
    }

    // ─── Share-Validierung ────────────────────────────────────────────────────

    public function isShareValid(array $share): bool
    {
        if (!($share['active'] ?? true)) {
            return false;
        }
        if (!empty($share['expires_at']) && $share['expires_at'] < time()) {
            return false;
        }
        return true;
    }

    public function verifyPassword(array $share, string $password): bool
    {
        if (empty($share['password_hash'])) {
            return true;
        }
        return password_verify($password, $share['password_hash']);
    }

    // ─── Gast-Galerie ─────────────────────────────────────────────────────────

    /**
     * Gibt Bilder und Unterordner für einen Share zurück inkl. aktueller NC-Tag-Bewertungen.
     * Filtert nach min_rating wenn gesetzt (verwendet NC-Tags).
     *
     * @param  string $subPath  Relativer Pfad innerhalb des Share-Roots (leer = Root)
     * @return array{images: array[], folders: array[]}
     */
    public function getImagesForShare(array $share, string $subPath = ''): array
    {
        $ownerId   = $share['owner_id'];
        $ncPath    = rtrim($share['nc_path'], '/');
        $minRating = (int) ($share['min_rating'] ?? 0);

        // Subpath bereinigen (Traversal-Schutz)
        $subPath = $this->sanitizeSubPath($subPath);

        $userFolder = $this->rootFolder->getUserFolder($ownerId);
        $fullPath   = $ncPath . $subPath;
        $folder     = $fullPath === '' || $fullPath === '/'
            ? $userFolder
            : $userFolder->get(ltrim($fullPath, '/'));

        if (!($folder instanceof Folder)) {
            throw new \RuntimeException("Pfad ist kein Ordner: {$fullPath}");
        }

        $images  = [];
        $folders = [];

        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof Folder) {
                if ($node->getName()[0] === '.') continue;
                $relPath   = $subPath . '/' . $node->getName();
                $folders[] = [
                    'name' => $node->getName(),
                    'path' => $relPath,
                ];
                continue;
            }
            if (!($node instanceof File)) {
                continue;
            }
            if (!in_array($node->getMimeType(), self::GUEST_MIME, true)) {
                continue;
            }
            $images[] = [
                'id'    => $node->getId(),
                'name'  => $node->getName(),
                'mtime' => $node->getMtime(),
                'size'  => $node->getSize(),
            ];
        }

        if (!empty($images)) {
            // Aktuelle NC-Tag-Bewertungen laden
            $fileIds  = array_map(fn($img) => (string) $img['id'], $images);
            $metadata = $this->tagService->getMetadataBatch($fileIds);

            foreach ($images as &$img) {
                $meta          = $metadata[(string) $img['id']] ?? ['rating' => 0, 'color' => null, 'pick' => 'none'];
                $img['rating'] = $meta['rating'];
                $img['color']  = $meta['color'];
                $img['pick']   = $meta['pick'];
            }
            unset($img);

            // min_rating filtern (basiert auf NC-Tags)
            if ($minRating > 0) {
                $images = array_values(array_filter($images, fn($img) => ($img['rating'] ?? 0) >= $minRating));
            }
        }

        return ['images' => $images, 'folders' => $folders];
    }

    /**
     * Liefert ein Thumbnail für einen Share (prüft Dateizugehörigkeit).
     */
    public function getThumbnailForShare(array $share, int $fileId, int $width = 400, int $height = 400): Response
    {
        $ownerId    = $share['owner_id'];
        $userFolder = $this->rootFolder->getUserFolder($ownerId);
        $nodes      = $userFolder->getById($fileId);

        $file = null;
        foreach ($nodes as $node) {
            if ($node instanceof File) {
                $file = $node;
                break;
            }
        }

        if ($file === null) {
            throw new \RuntimeException("Datei nicht gefunden: {$fileId}");
        }

        // Sicherheit: Datei muss im freigegebenen Ordner liegen
        $sharePath = rtrim($share['nc_path'], '/');
        if (!str_starts_with($file->getPath(), $userFolder->getPath() . '/' . ltrim($sharePath, '/'))) {
            throw new \RuntimeException("File is not in the shared folder.");
        }

        $width  = min(max($width,  32), 3840);
        $height = min(max($height, 32), 2160);

        $crop     = ($width <= 800);  // Thumbnails: crop; Previews: kein Crop
        $preview  = $this->previewManager->getPreview($file, $width, $height, $crop);
        $response = new FileDisplayResponse($preview, 200, [
            'Content-Type' => $preview->getMimeType(),
        ]);
        $response->cacheFor(3600);
        return $response;
    }

    // ─── Gast-Bewertungen ─────────────────────────────────────────────────────

    /**
     * Speichert eine Gast-Bewertung als echte NC Collaborative Tags.
     * Schreibt zusätzlich einen Log-Eintrag (für den Fotografen).
     *
     * @return array{file_id: int, rating: int|null, color: string|null, guest_name: string, timestamp: int}
     */
    public function saveGuestRating(
        array   $share,
        int     $fileId,
        ?int    $rating,
        ?string $color,
        ?string $pick,
        string  $guestName,
    ): array {
        $token     = $share['token'];
        $ownerId   = $share['owner_id'];
        $fileIdStr = (string) $fileId;

        // Echte NC-Tags setzen (identisch zu normalem User-Rating)
        $metadata = [];
        if ($rating !== null) {
            $metadata['rating'] = $rating;
        }
        if ($color !== null) {
            $metadata['color'] = $color;
        }
        if ($pick !== null) {
            $metadata['pick'] = $pick;
        }
        if (!empty($metadata)) {
            $this->tagService->setMetadata($fileIdStr, $metadata);
        }

        $filename = $this->resolveFilename($ownerId, $fileId);

        // Log-Eintrag schreiben
        $entry = [
            'file_id'    => $fileId,
            'filename'   => $filename,
            'rating'     => $rating,
            'color'      => $color,
            'pick'       => $pick,
            'guest_name' => $guestName ?: 'Guest',
            'timestamp'  => time(),
        ];

        $this->appendToLog($ownerId, $token, $entry);

        $parts = [];
        if ($rating !== null)  $parts[] = "Rating {$rating}";
        if ($color  !== null)  $parts[] = "Farbe {$color}";
        if ($pick   !== null)  $parts[] = "Pick {$pick}";
        $action = $parts ? implode(', ', $parts) : 'no change';

        $this->logger->info(
            "StarRate: Gast '{$entry['guest_name']}' hat Datei {$fileId} gesetzt: {$action} (Share {$token})."
        );

        return $entry;
    }

    // ─── Gast-Log ─────────────────────────────────────────────────────────────

    /**
     * Gibt alle Log-Einträge eines Shares zurück (neueste zuerst).
     *
     * @return array[]  [{file_id, rating, color, guest_name, timestamp}, ...]
     */
    public function getGuestLog(string $token): array
    {
        $share = $this->getShare($token);
        if ($share === null) {
            return [];
        }

        $key = self::CONFIG_LOG . '_' . $token;
        $raw = $this->config->getUserValue($share['owner_id'], self::APP_ID, $key, '[]');
        $log = json_decode($raw, true);
        if (!is_array($log)) {
            return [];
        }
        return array_reverse($log);  // neueste zuerst
    }

    /**
     * Löscht das gesamte Log eines Shares.
     */
    public function clearGuestLog(string $token): void
    {
        $share = $this->getShare($token);
        if ($share === null) {
            return;
        }
        $this->config->setUserValue(
            $share['owner_id'], self::APP_ID, self::CONFIG_LOG . '_' . $token, '[]'
        );
    }

    /**
     * Entfernt alle Log-Einträge älter als $before (Unix-Timestamp).
     */
    public function trimGuestLog(string $token, int $before): void
    {
        $share = $this->getShare($token);
        if ($share === null) {
            return;
        }

        $ownerId  = $share['owner_id'];
        $key      = self::CONFIG_LOG . '_' . $token;
        $raw      = $this->config->getUserValue($ownerId, self::APP_ID, $key, '[]');
        $log      = json_decode($raw, true);
        if (!is_array($log)) {
            return;
        }

        $filtered = array_values(array_filter($log, fn($e) => ($e['timestamp'] ?? 0) >= $before));
        $this->config->setUserValue($ownerId, self::APP_ID, $key, json_encode($filtered));
    }

    // ─── Kommentare ───────────────────────────────────────────────────────────

    /**
     * Speichert oder aktualisiert einen Kommentar zu einer Datei (UPSERT).
     * Letzter Schreiber gewinnt — ein Kommentar pro Foto global.
     *
     * @return array{file_id: int, comment: string, author_type: string, author_name: string|null, updated_at: int}
     */
    public function saveComment(int $fileId, string $comment, string $authorType, ?string $authorName): array
    {
        $comment = mb_substr(trim($comment), 0, 2000);
        $now     = time();

        // UPDATE zuerst — Race-Condition-sicher: bei gleichzeitigem INSERT greift der UNIQUE-Constraint.
        $qb      = $this->db->getQueryBuilder();
        $updated = $qb->update('starrate_comments')
            ->set('comment',     $qb->createNamedParameter($comment))
            ->set('author_type', $qb->createNamedParameter($authorType))
            ->set('author_name', $qb->createNamedParameter($authorName))
            ->set('updated_at',  $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        if ($updated === 0) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('starrate_comments')
                    ->values([
                        'file_id'     => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                        'comment'     => $qb->createNamedParameter($comment),
                        'author_type' => $qb->createNamedParameter($authorType),
                        'author_name' => $qb->createNamedParameter($authorName),
                        'updated_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                    ])
                    ->executeStatement();
            } catch (\Exception) {
                // Race: anderer Request hat gerade denselben fileId eingefügt → aktuellen Wert zurückgeben
                return $this->getComment($fileId) ?? [
                    'file_id' => $fileId, 'comment' => $comment,
                    'author_type' => $authorType, 'author_name' => $authorName, 'updated_at' => $now,
                ];
            }
        }

        return [
            'file_id'     => $fileId,
            'comment'     => $comment,
            'author_type' => $authorType,
            'author_name' => $authorName,
            'updated_at'  => $now,
        ];
    }

    /**
     * Liest den Kommentar zu einer Datei.
     *
     * @return array{file_id: int, comment: string, author_type: string, author_name: string|null, updated_at: int}|null
     */
    public function getComment(int $fileId): ?array
    {
        $qb     = $this->db->getQueryBuilder();
        $result = $qb->select('file_id', 'comment', 'author_type', 'author_name', 'updated_at')
            ->from('starrate_comments')
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->executeQuery()
            ->fetch();

        if ($result === false) {
            return null;
        }

        return [
            'file_id'     => (int) $result['file_id'],
            'comment'     => $result['comment'],
            'author_type' => $result['author_type'],
            'author_name' => $result['author_name'],
            'updated_at'  => (int) $result['updated_at'],
        ];
    }

    /**
     * Prüft ob eine Datei zum Share-Ordner gehört (Sicherheitscheck für Gäste).
     */
    public function fileExistsInShare(array $share, int $fileId): bool
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($share['owner_id']);
            $nodes      = $userFolder->getById($fileId);
            $shareRoot  = $userFolder->getPath() . rtrim($share['nc_path'], '/') . '/';
            foreach ($nodes as $node) {
                if (str_starts_with($node->getPath(), $shareRoot)) {
                    return true;
                }
            }
        } catch (\Exception) {
            // ignore
        }
        return false;
    }

    /**
     * Schreibt einen Kommentar-Eintrag in den Gast-Log.
     */
    public function appendCommentToLog(array $share, int $fileId, string $comment, string $guestName): void
    {
        $this->appendToLog($share['owner_id'], $share['token'], [
            'file_id'    => $fileId,
            'filename'   => $this->resolveFilename($share['owner_id'], $fileId),
            'comment'    => mb_substr($comment, 0, 200),
            'guest_name' => $guestName,
            'timestamp'  => time(),
        ]);
    }

    /**
     * Löscht den Kommentar zu einer Datei (auch beim File-Delete aufgerufen).
     */
    public function deleteComment(int $fileId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('starrate_comments')
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    // ─── Log-Hilfsmethoden ────────────────────────────────────────────────────

    /**
     * Löst eine file_id in einen Dateinamen auf (Fallback: ID als String).
     * Wird für Log-Einträge verwendet — darf nie werfen.
     */
    private function resolveFilename(string $ownerId, int $fileId): string
    {
        try {
            $nodes = $this->rootFolder->getUserFolder($ownerId)->getById($fileId);
            foreach ($nodes as $n) {
                if ($n instanceof File) {
                    return $n->getName();
                }
            }
        } catch (\Exception) { /* ignore */ }
        return (string) $fileId;
    }

    /**
     * Hängt einen Eintrag an den Gast-Log an und trimmt ihn auf MAX_ENTRIES.
     */
    private function appendToLog(string $ownerId, string $token, array $entry): void
    {
        $key = self::CONFIG_LOG . '_' . $token;
        $raw = $this->config->getUserValue($ownerId, self::APP_ID, $key, '[]');
        $log = json_decode($raw, true);
        if (!is_array($log)) {
            $log = [];
        }
        $log[] = $entry;
        if (count($log) > self::GUEST_LOG_MAX_ENTRIES) {
            $log = array_slice($log, -self::GUEST_LOG_MAX_ENTRIES);
        }
        $this->config->setUserValue($ownerId, self::APP_ID, $key, json_encode($log));
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    /**
     * Bereinigt einen relativen Unterpfad gegen Directory-Traversal-Angriffe.
     * Liefert immer entweder '' oder einen Pfad der mit '/' beginnt.
     */
    private function sanitizeSubPath(string $subPath): string
    {
        if ($subPath === '' || $subPath === '/') {
            return '';
        }

        $parts  = explode('/', str_replace('\\', '/', $subPath));
        $result = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                // Traversal-Versuch → einfach ignorieren
                continue;
            }
            $result[] = $part;
        }
        return $result ? '/' . implode('/', $result) : '';
    }

    private function loadAllShares(string $userId): array
    {
        $raw    = $this->config->getUserValue($userId, self::APP_ID, self::CONFIG_SHARES, '{}');
        $shares = json_decode($raw, true);
        return is_array($shares) ? $shares : [];
    }

    private function saveShares(string $userId, array $shares): void
    {
        $this->config->setUserValue($userId, self::APP_ID, self::CONFIG_SHARES, json_encode($shares));
    }

    private function validatePermissions(string $permissions): void
    {
        if (!in_array($permissions, [self::PERM_VIEW, self::PERM_RATE], true)) {
            throw new \InvalidArgumentException(
                "Ungültige Berechtigung: {$permissions}. Erlaubt: view, rate"
            );
        }
    }
}

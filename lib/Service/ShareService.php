<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IPreview as IPreviewManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Verwaltet öffentliche Freigabe-Links für Gäste (Modelle, Kunden).
 *
 * Shares werden pro Benutzer in der NC-Config gespeichert.
 * Gast-Bewertungen werden ebenfalls dort abgelegt.
 */
class ShareService
{
    public const PERM_VIEW = 'view';
    public const PERM_RATE = 'rate';

    private const APP_ID          = 'starrate';
    private const CONFIG_SHARES   = 'starrate_shares';
    private const CONFIG_RATINGS  = 'starrate_guest_ratings';
    private const TOKEN_LENGTH    = 24;

    // Unterstützte MIME-Typen für Gast-Galerie
    private const GUEST_MIME = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly IConfig         $config,
        private readonly IRootFolder     $rootFolder,
        private readonly IPreviewManager $previewManager,
        private readonly ISecureRandom   $secureRandom,
        private readonly LoggerInterface $logger,
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
            'created_at'    => time(),
            'active'        => true,
        ];

        $allShares           = $this->loadAllShares($ownerId);
        $allShares[$token]   = $share;
        $this->saveShares($ownerId, $allShares);

        // Passwort-Hash nicht nach außen geben
        $safe          = $share;
        $safe['has_password'] = $password !== null;
        unset($safe['password_hash']);

        $this->logger->info("StarRate: Share {$token} erstellt von {$ownerId} für {$ncPath}.");
        return $safe;
    }

    /**
     * Lädt einen Share anhand des Tokens (sucht in allen Benutzern).
     */
    public function getShare(string $token): ?array
    {
        // Token enthält owner_id im Payload → wir suchen in allen User-Configs
        // Effizienter: Owner-ID im Token kodieren, hier: lineare Suche über DB
        $qb = \OC::$server->getDatabaseConnection()->getQueryBuilder();
        $result = $qb->select('userid', 'configvalue')
            ->from('preferences')
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter(self::APP_ID)))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(self::CONFIG_SHARES)))
            ->executeQuery();

        while ($row = $result->fetch()) {
            $shares = json_decode($row['configvalue'], true);
            if (is_array($shares) && isset($shares[$token])) {
                $share = $shares[$token];
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
        $share    = $this->getShare($token);
        if ($share === null) {
            throw new \RuntimeException("Share {$token} nicht gefunden.");
        }

        $ownerId  = $share['owner_id'];
        $all      = $this->loadAllShares($ownerId);

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

        $this->saveShares($ownerId, $all);

        $updated = $all[$token];
        $updated['has_password'] = !empty($updated['password_hash']);
        unset($updated['password_hash']);
        return $updated;
    }

    /**
     * Löscht einen Share.
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

        $this->logger->info("StarRate: Share {$token} gelöscht.");
    }

    // ─── Share-Validierung ────────────────────────────────────────────────────

    /**
     * Prüft ob ein Share aktiv und nicht abgelaufen ist.
     */
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

    /**
     * Verifiziert das Passwort eines Shares.
     */
    public function verifyPassword(array $share, string $password): bool
    {
        if (empty($share['password_hash'])) {
            return true;
        }
        return password_verify($password, $share['password_hash']);
    }

    // ─── Gast-Galerie ─────────────────────────────────────────────────────────

    /**
     * Gibt alle Bilder für einen Share zurück (gefiltert nach min_rating).
     *
     * @return array[]
     */
    public function getImagesForShare(array $share): array
    {
        $ownerId   = $share['owner_id'];
        $ncPath    = $share['nc_path'];
        $minRating = (int) ($share['min_rating'] ?? 0);

        $userFolder = $this->rootFolder->getUserFolder($ownerId);
        $folder     = $ncPath === '/' ? $userFolder : $userFolder->get(ltrim($ncPath, '/'));

        if (!($folder instanceof Folder)) {
            throw new \RuntimeException("Pfad ist kein Ordner: {$ncPath}");
        }

        $images = [];
        foreach ($folder->getDirectoryListing() as $node) {
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

        // Wenn min_rating gesetzt → Metadaten laden und filtern
        if ($minRating > 0 && !empty($images)) {
            $guestRatings = $this->getGuestRatingsForShare($share['token']);
            $images = array_values(array_filter($images, function (array $img) use ($minRating, $guestRatings): bool {
                $fileId = (string) $img['id'];
                $rating = 0;
                // Gast-Bewertungen berücksichtigen
                if (isset($guestRatings[$fileId])) {
                    $ratings = array_column($guestRatings[$fileId], 'rating');
                    $rating  = !empty($ratings) ? max($ratings) : 0;
                }
                return $rating >= $minRating;
            }));
        }

        return $images;
    }

    /**
     * Liefert ein Thumbnail für einen Share (prüft Dateizugehörigkeit).
     */
    public function getThumbnailForShare(array $share, int $fileId): Response
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
            throw new \RuntimeException("Datei liegt nicht im freigegebenen Ordner.");
        }

        $preview = $this->previewManager->getPreview($file, 400, 400, true);
        $response = new FileDisplayResponse($preview, 200, [
            'Content-Type' => $preview->getMimeType(),
        ]);
        $response->cacheFor(3600);
        return $response;
    }

    // ─── Gast-Bewertungen ────────────────────────────────────────────────────

    /**
     * Speichert eine Gast-Bewertung.
     *
     * @return array{file_id: int, rating: int|null, color: string|null, guest_name: string, timestamp: int}
     */
    public function saveGuestRating(
        array   $share,
        int     $fileId,
        ?int    $rating,
        ?string $color,
        string  $guestName,
    ): array {
        $token   = $share['token'];
        $ownerId = $share['owner_id'];

        $entry = [
            'file_id'    => $fileId,
            'rating'     => $rating,
            'color'      => $color,
            'guest_name' => $guestName ?: 'Gast',
            'timestamp'  => time(),
        ];

        $key     = self::CONFIG_RATINGS . '_' . $token;
        $raw     = $this->config->getUserValue($ownerId, self::APP_ID, $key, '{}');
        $ratings = json_decode($raw, true);
        if (!is_array($ratings)) {
            $ratings = [];
        }

        $fileKey = (string) $fileId;
        if (!isset($ratings[$fileKey])) {
            $ratings[$fileKey] = [];
        }

        // Bestehende Bewertung des gleichen Gastes überschreiben
        $found = false;
        foreach ($ratings[$fileKey] as &$existing) {
            if ($existing['guest_name'] === $entry['guest_name']) {
                $existing = $entry;
                $found    = true;
                break;
            }
        }
        unset($existing);

        if (!$found) {
            $ratings[$fileKey][] = $entry;
        }

        $this->config->setUserValue($ownerId, self::APP_ID, $key, json_encode($ratings));

        $this->logger->debug("StarRate: Gast-Bewertung von '{$guestName}' für Datei {$fileId} gespeichert.");
        return $entry;
    }

    /**
     * Gibt alle Gast-Bewertungen für einen Share zurück.
     *
     * @return array<string, array[]>  fileId (string) → Liste von Gast-Bewertungen
     */
    public function getGuestRatingsForShare(string $token): array
    {
        $share = $this->getShare($token);
        if ($share === null) {
            return [];
        }

        $ownerId = $share['owner_id'];
        $key     = self::CONFIG_RATINGS . '_' . $token;
        $raw     = $this->config->getUserValue($ownerId, self::APP_ID, $key, '{}');
        $ratings = json_decode($raw, true);
        return is_array($ratings) ? $ratings : [];
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    /**
     * Lädt alle Shares eines Benutzers aus der Config.
     *
     * @return array<string, array>  token → share
     */
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
            throw new \InvalidArgumentException("Ungültige Berechtigung: {$permissions}. Erlaubt: view, rate");
        }
    }
}

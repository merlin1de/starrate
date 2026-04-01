<?php

declare(strict_types=1);

namespace OCA\StarRate\Controller;

use OCA\StarRate\Service\ShareService;
use OCA\StarRate\Service\TagService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ShareController extends Controller
{
    public function __construct(
        string                        $appName,
        IRequest                      $request,
        private readonly ShareService  $shareService,
        private readonly TagService    $tagService,
        private readonly IUserSession  $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ─── Verwaltung (eingeloggte Benutzer) ────────────────────────────────────

    /**
     * POST /api/share — erstellt einen neuen Freigabe-Link.
     *
     * Body (JSON): {
     *   "nc_path": "/Fotos/Shooting-2024",
     *   "password": "geheim",          // optional
     *   "expires_at": 1735689600,      // Unix-Timestamp, optional
     *   "min_rating": 3,               // Vorfilter, optional (0 = alle)
     *   "permissions": "rate"          // "view" | "rate", default "view"
     * }
     */
    #[NoAdminRequired]
    public function create(): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        $body   = $this->getJsonBody();
        $errors = $this->validateShareBody($body);
        if (!empty($errors)) {
            return new DataResponse(['error' => implode('; ', $errors)], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        try {
            $share = $this->shareService->createShare(
                $userId,
                $body['nc_path'],
                $body['password']    ?? null,
                isset($body['expires_at']) ? (int) $body['expires_at'] : null,
                isset($body['min_rating']) ? (int) $body['min_rating'] : 0,
                $body['permissions'] ?? ShareService::PERM_VIEW,
                $body['guest_name']  ?? null,
            );
            return new DataResponse(['share' => $share], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            $this->logger->error("StarRate ShareController::create – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/share — listet alle aktiven Freigaben des Benutzers.
     */
    #[NoAdminRequired]
    public function list(): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $shares = $this->shareService->getSharesByOwner($userId);
            return new DataResponse(['shares' => $shares]);
        } catch (\Exception $e) {
            $this->logger->error("StarRate ShareController::list – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/share/{token} — Metadaten eines Shares abrufen (für Owner).
     */
    #[NoAdminRequired]
    public function get(string $token): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $share = $this->shareService->getShare($token);
            if ($share === null || $share['owner_id'] !== $userId) {
                return new DataResponse(['error' => 'Nicht gefunden'], Http::STATUS_NOT_FOUND);
            }
            return new DataResponse(['share' => $share]);
        } catch (\Exception $e) {
            $this->logger->error("StarRate ShareController::get – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT /api/share/{token} — Freigabe aktualisieren (Passwort, Ablaufdatum, Berechtigungen).
     */
    #[NoAdminRequired]
    public function update(string $token): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        $body  = $this->getJsonBody();
        $share = $this->shareService->getShare($token);

        if ($share === null || $share['owner_id'] !== $userId) {
            return new DataResponse(['error' => 'Nicht gefunden'], Http::STATUS_NOT_FOUND);
        }

        try {
            $updated = $this->shareService->updateShare($token, $body);
            return new DataResponse(['share' => $updated]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            $this->logger->error("StarRate ShareController::update – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE /api/share/{token} — Freigabe deaktivieren/löschen.
     */
    #[NoAdminRequired]
    public function delete(string $token): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        $share = $this->shareService->getShare($token);
        if ($share === null || $share['owner_id'] !== $userId) {
            return new DataResponse(['error' => 'Nicht gefunden'], Http::STATUS_NOT_FOUND);
        }

        try {
            $this->shareService->deleteShare($token);
            return new DataResponse(['ok' => true]);
        } catch (\Exception $e) {
            $this->logger->error("StarRate ShareController::delete – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Gast-Log (eingeloggte Benutzer) ──────────────────────────────────────

    /**
     * GET /api/share/{token}/log — Log-Einträge eines Shares abrufen.
     */
    #[NoAdminRequired]
    public function getLog(string $token): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        $share = $this->shareService->getShare($token);
        if ($share === null || $share['owner_id'] !== $userId) {
            return new DataResponse(['error' => 'Nicht gefunden'], Http::STATUS_NOT_FOUND);
        }

        return new DataResponse(['log' => $this->shareService->getGuestLog($token)]);
    }

    /**
     * DELETE /api/share/{token}/log — Log löschen oder einkürzen.
     *
     * Query: ?before=<unix-timestamp>  → nur Einträge älter als before löschen
     * Ohne before                      → gesamtes Log löschen
     */
    #[NoAdminRequired]
    public function deleteLog(string $token): DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        $share = $this->shareService->getShare($token);
        if ($share === null || $share['owner_id'] !== $userId) {
            return new DataResponse(['error' => 'Nicht gefunden'], Http::STATUS_NOT_FOUND);
        }

        $before = $this->request->getParam('before');
        if ($before !== null) {
            $this->shareService->trimGuestLog($token, (int) $before);
        } else {
            $this->shareService->clearGuestLog($token);
        }

        return new DataResponse(['ok' => true]);
    }

    // ─── Gast-Zugriff (öffentlich, kein Login) ───────────────────────────────

    /**
     * GET /guest/{token} — rendert die Gast-Galerie-SPA.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function guestView(string $token): TemplateResponse
    {
        $share = $this->shareService->getShare($token);

        if ($share === null || !$this->shareService->isShareValid($share)) {
            return new TemplateResponse($this->appName, 'share_expired', [], 'public');
        }

        // Passwortprüfung läuft über die API (guestImages gibt 401 zurück),
        // die Vue-SPA zeigt den Passwort-Dialog — keine separate PHP-Seite nötig.

        return new TemplateResponse($this->appName, 'guest', [
            'token'      => $token,
            'share'      => $share,
            'min_rating' => $share['min_rating'] ?? 0,
            'can_rate'   => $share['permissions'] === ShareService::PERM_RATE,
            'guest_name' => $share['guest_name'] ?? '',
        ], 'public');
    }

    /**
     * GET /api/guest/{token}/images — Bilder im freigegebenen Ordner inkl. Bewertungen.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function guestImages(string $token): DataResponse
    {
        $share = $this->getValidShare($token);
        if ($share === null) {
            return new DataResponse(['error' => 'Link ungültig oder abgelaufen'], Http::STATUS_FORBIDDEN);
        }

        if (!$this->checkGuestPassword($token, $share)) {
            return new DataResponse(['error' => 'Passwort erforderlich'], Http::STATUS_UNAUTHORIZED);
        }

        $subPath = (string) ($this->request->getParam('path', ''));

        try {
            $result = $this->shareService->getImagesForShare($share, $subPath);
            return new DataResponse([
                'images'  => $result['images'],
                'folders' => $result['folders'],
                'total'   => count($result['images']),
            ]);
        } catch (\Exception $e) {
            $this->logger->error("StarRate ShareController::guestImages – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/guest/{token}/thumbnail/{fileId} — Thumbnail für Gäste.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function guestThumbnail(string $token, int $fileId): DataResponse|\OCP\AppFramework\Http\Response
    {
        $share = $this->getValidShare($token);
        if ($share === null) {
            return new DataResponse(['error' => 'Link ungültig'], Http::STATUS_FORBIDDEN);
        }

        if (!$this->checkGuestPassword($token, $share)) {
            return new DataResponse(['error' => 'Passwort erforderlich'], Http::STATUS_UNAUTHORIZED);
        }

        $width  = min(max((int) ($this->request->getParam('width',  400)), 32), 3840);
        $height = min(max((int) ($this->request->getParam('height', 400)), 32), 2160);

        try {
            return $this->shareService->getThumbnailForShare($share, $fileId, $width, $height);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Vorschau nicht verfügbar'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * GET /api/guest/{token}/preview/{fileId} — Vollbild-Preview für Gäste (LoupeView).
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function guestPreview(string $token, int $fileId): DataResponse|\OCP\AppFramework\Http\Response
    {
        $share = $this->getValidShare($token);
        if ($share === null) {
            return new DataResponse(['error' => 'Link ungültig'], Http::STATUS_FORBIDDEN);
        }

        if (!$this->checkGuestPassword($token, $share)) {
            return new DataResponse(['error' => 'Passwort erforderlich'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return $this->shareService->getThumbnailForShare($share, $fileId, 1920, 1200);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Vorschau nicht verfügbar'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * POST /api/guest/{token}/rate — Gast bewertet ein Bild.
     *
     * Body (JSON): {
     *   "file_id": 123,
     *   "rating": 4,
     *   "color": "Green",       // optional
     *   "guest_name": "Anna"    // optional
     * }
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function guestRate(string $token): DataResponse
    {
        $share = $this->getValidShare($token);
        if ($share === null) {
            return new DataResponse(['error' => 'Link ungültig oder abgelaufen'], Http::STATUS_FORBIDDEN);
        }

        if ($share['permissions'] !== ShareService::PERM_RATE) {
            return new DataResponse(['error' => 'Keine Bewertungsberechtigung'], Http::STATUS_FORBIDDEN);
        }

        if (!$this->checkGuestPassword($token, $share)) {
            return new DataResponse(['error' => 'Passwort erforderlich'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->getJsonBody();

        if (empty($body['file_id'])) {
            return new DataResponse(['error' => 'file_id fehlt'], Http::STATUS_BAD_REQUEST);
        }

        $rating    = isset($body['rating']) ? (int) $body['rating'] : null;
        $color     = $body['color'] ?? null;
        $pick      = $body['pick'] ?? null;
        $guestName = trim($body['guest_name'] ?? 'Gast');

        if ($rating !== null && ($rating < 0 || $rating > 5)) {
            return new DataResponse(['error' => 'rating muss zwischen 0 und 5 liegen'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        if ($pick !== null && !in_array($pick, TagService::VALID_PICKS, true)) {
            return new DataResponse(['error' => 'Ungültiger Pick-Status'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->shareService->saveGuestRating(
                $share,
                (int) $body['file_id'],
                $rating,
                $color,
                $pick,
                $guestName,
            );
            return new DataResponse($result, Http::STATUS_OK);
        } catch (\Exception $e) {
            $this->logger->error("StarRate ShareController::guestRate – {$e->getMessage()}");
            return new DataResponse(['error' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/guest/{token}/verify — Passwort für geschützten Share prüfen.
     *
     * Body (JSON): { "password": "geheim" }
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function guestVerifyPassword(string $token): DataResponse
    {
        $share = $this->shareService->getShare($token);

        if ($share === null || !$this->shareService->isShareValid($share)) {
            return new DataResponse(['error' => 'Link ungültig'], Http::STATUS_FORBIDDEN);
        }

        $body     = $this->getJsonBody();
        $password = $body['password'] ?? '';

        if ($this->shareService->verifyPassword($share, $password)) {
            $session = \OC::$server->getSession();
            $session->set("starrate_share_{$token}", true);
            // pw_token: persistenter Nachweis für mobile Browser (localStorage)
            $pwToken = hash_hmac('sha256', $token, $share['password_hash']);
            return new DataResponse(['ok' => true, 'pw_token' => $pwToken]);
        }

        return new DataResponse(['error' => 'Falsches Passwort'], Http::STATUS_UNAUTHORIZED);
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    private function getValidShare(string $token): ?array
    {
        $share = $this->shareService->getShare($token);
        if ($share === null || !$this->shareService->isShareValid($share)) {
            return null;
        }
        return $share;
    }

    private function checkGuestPassword(string $token, array $share): bool
    {
        if (empty($share['password_hash'])) {
            return true;
        }

        // 1. PHP-Session (Desktop-Browser, kurze Sitzungen)
        $session = \OC::$server->getSession();
        if ($session->get("starrate_share_{$token}") === true) {
            return true;
        }

        // 2. pw_token (Mobile: per Header oder Query-Parameter aus localStorage)
        $pwToken = $this->request->getHeader('X-StarRate-Pw-Token')
            ?: (string) ($this->request->getParam('pw_token', ''));

        if ($pwToken !== '') {
            $expected = hash_hmac('sha256', $token, $share['password_hash']);
            return hash_equals($expected, $pwToken);
        }

        return false;
    }

    private function validateShareBody(array $body): array
    {
        $errors = [];

        if (empty($body['nc_path'])) {
            $errors[] = 'nc_path ist erforderlich';
        }

        if (isset($body['expires_at']) && (int) $body['expires_at'] < time()) {
            $errors[] = 'expires_at liegt in der Vergangenheit';
        }

        if (isset($body['min_rating'])) {
            $r = (int) $body['min_rating'];
            if ($r < 0 || $r > 5) {
                $errors[] = 'min_rating muss zwischen 0 und 5 liegen';
            }
        }

        if (isset($body['permissions'])
            && !in_array($body['permissions'], [ShareService::PERM_VIEW, ShareService::PERM_RATE], true)
        ) {
            $errors[] = 'permissions muss "view" oder "rate" sein';
        }

        return $errors;
    }

    private function getJsonBody(): array
    {
        $raw     = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '{}', true);
        return is_array($decoded) ? $decoded : [];
    }

    private function getUserId(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }
}

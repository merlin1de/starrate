<?php

declare(strict_types=1);

namespace OCA\StarRate\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;

/**
 * Gemeinsame Hilfsmethoden für alle StarRate-Controller.
 *
 * Erwartet, dass die nutzende Klasse folgende Properties besitzt:
 *   - $this->userSession  (IUserSession)
 *   - $this->rootFolder   (IRootFolder)       — nur wenn getFileById() genutzt wird
 *   - $this->request      (IRequest)           — via Controller-Basisklasse
 */
trait StarRateControllerTrait
{
    /**
     * Gibt die UID des eingeloggten Benutzers zurück (null wenn nicht authentifiziert).
     */
    private function getUserId(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }

    /**
     * Prüft Authentifizierung und gibt die userId zurück.
     * Gibt eine 401-DataResponse zurück falls nicht eingeloggt.
     *
     * Nutzung:
     *   $auth = $this->requireAuth();
     *   if ($auth instanceof DataResponse) return $auth;
     *   $userId = $auth;
     *
     * @return string|DataResponse
     */
    private function requireAuth(): string|DataResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }
        return $userId;
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

    /**
     * Liest den JSON-Body des Requests als assoziatives Array.
     * Nutzt php://input direkt, fällt bei leerem Stream (z.B. PHPUnit)
     * auf $this->request->getParams() zurück (gefiltert um NC-Route-Keys).
     */
    private function getJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        // Fallback: Request-Params (ohne NC-interne Route-Keys)
        $params = $this->request->getParams();
        unset($params['_route'], $params['_rawParams']);
        return $params;
    }
}

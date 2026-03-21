<?php

declare(strict_types=1);

namespace OCA\StarRate\Controller;

use OCA\StarRate\Settings\UserSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends Controller
{
    public function __construct(
        string                        $appName,
        IRequest                      $request,
        private readonly UserSettings $userSettings,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /** GET /api/settings */
    #[NoAdminRequired]
    public function getSettings(): DataResponse
    {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }
        return new DataResponse($this->userSettings->getSettings($userId));
    }

    /** POST /api/settings */
    #[NoAdminRequired]
    public function saveSettings(): DataResponse
    {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new DataResponse(['error' => 'Nicht authentifiziert'], Http::STATUS_UNAUTHORIZED);
        }

        $raw  = $this->request->getContent();
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) {
            return new DataResponse(['error' => 'Ungültiger JSON-Body'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->userSettings->saveSettings($userId, $data);
            return new DataResponse($this->userSettings->getSettings($userId));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }
    }
}

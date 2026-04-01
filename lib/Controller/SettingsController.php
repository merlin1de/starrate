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
    use StarRateControllerTrait;

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
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        return new DataResponse($this->userSettings->getSettings($userId));
    }

    /** POST /api/settings */
    #[NoAdminRequired]
    public function saveSettings(): DataResponse
    {
        $auth = $this->requireAuth();
        if ($auth instanceof DataResponse) return $auth;
        $userId = $auth;

        $data = $this->getJsonBody();

        try {
            $this->userSettings->saveSettings($userId, $data);
            return new DataResponse($this->userSettings->getSettings($userId));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        }
    }
}

<?php

declare(strict_types=1);

namespace OCA\StarRate\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\IUserSession;

/**
 * Persönliche Einstellungen für StarRate.
 * Erscheint unter Nextcloud → Einstellungen → Persönlich → StarRate.
 */
class UserSettings implements ISettings
{
    private const APP_ID = 'starrate';

    public function __construct(
        private readonly IConfig      $config,
        private readonly IUserSession $userSession,
    ) {}

    public function getForm(): TemplateResponse
    {
        $userId   = $this->userSession->getUser()?->getUID() ?? '';
        $settings = $this->getSettings($userId);

        return new TemplateResponse(self::APP_ID, 'settings/personal', [
            'settings' => $settings,
        ], '');
    }

    public function getSection(): string
    {
        return 'starrate';
    }

    public function getPriority(): int
    {
        return 50;
    }

    // ─── Einstellungen lesen / schreiben ──────────────────────────────────────

    /**
     * Gibt alle Benutzereinstellungen zurück.
     *
     * @return array{
     *   default_sort: string,
     *   default_sort_order: string,
     *   thumbnail_size: int,
     *   show_filename: bool,
     *   show_rating_overlay: bool,
     *   show_color_overlay: bool,
     *   grid_columns: string,
     * }
     */
    public function getSettings(string $userId): array
    {
        return [
            'default_sort'          => $this->get($userId, 'default_sort', 'name'),
            'default_sort_order'    => $this->get($userId, 'default_sort_order', 'asc'),
            'thumbnail_size'        => (int) $this->get($userId, 'thumbnail_size', '280'),
            'show_filename'         => $this->getBool($userId, 'show_filename', true),
            'show_rating_overlay'   => $this->getBool($userId, 'show_rating_overlay', true),
            'show_color_overlay'    => $this->getBool($userId, 'show_color_overlay', true),
            'grid_columns'          => $this->get($userId, 'grid_columns', 'auto'),
        ];
    }

    /**
     * Speichert eine oder mehrere Einstellungen.
     *
     * @param  array<string, mixed> $data
     * @throws \InvalidArgumentException bei ungültigen Werten
     */
    public function saveSettings(string $userId, array $data): void
    {
        $allowed = [
            'default_sort', 'default_sort_order', 'thumbnail_size',
            'show_filename', 'show_rating_overlay',
            'show_color_overlay', 'grid_columns',
        ];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Unbekannte Einstellung: {$key}");
            }

            $this->validate($key, $value);
            $this->config->setUserValue(
                $userId,
                self::APP_ID,
                $key,
                is_bool($value) ? ($value ? '1' : '0') : (string) $value
            );
        }
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    private function get(string $userId, string $key, string $default): string
    {
        return $this->config->getUserValue($userId, self::APP_ID, $key, $default);
    }

    private function getBool(string $userId, string $key, bool $default): bool
    {
        $val = $this->config->getUserValue($userId, self::APP_ID, $key, null);
        if ($val === null) return $default;
        return in_array($val, ['1', 'true', 'yes'], true);
    }

    private function validate(string $key, mixed $value): void
    {
        match ($key) {
            'default_sort' => $this->assertIn($key, $value, ['name', 'mtime', 'size']),
            'default_sort_order' => $this->assertIn($key, $value, ['asc', 'desc']),
            'thumbnail_size' => $this->assertRange($key, (int) $value, 120, 600),
            'grid_columns' => $this->assertIn($key, $value, ['auto', '2', '3', '4', '5', '6', '8']),
            'show_filename', 'show_rating_overlay', 'show_color_overlay' => null,
        };
    }

    private function assertIn(string $key, mixed $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "{$key} muss einer von " . implode(', ', $allowed) . " sein."
            );
        }
    }

    private function assertRange(string $key, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("{$key} muss zwischen {$min} und {$max} liegen.");
        }
    }
}

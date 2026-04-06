<?php

declare(strict_types=1);

namespace OCA\StarRate\Tests\Unit\Settings;

use OCA\StarRate\Settings\UserSettings;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserSettingsTest extends TestCase
{
    private UserSettings $settings;

    /** @var IConfig&MockObject */
    private IConfig $config;
    /** @var IUserSession&MockObject */
    private IUserSession $userSession;

    private const USER_ID = 'testuser';

    protected function setUp(): void
    {
        $this->config      = $this->createMock(IConfig::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn(self::USER_ID);
        $this->userSession->method('getUser')->willReturn($user);

        $this->settings = new UserSettings($this->config, $this->userSession);
    }

    // ─── getSettings ─────────────────────────────────────────────────────────

    public function testGetSettingsReturnsDefaults(): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $default) {
                return $default;
            });

        $result = $this->settings->getSettings(self::USER_ID);

        $this->assertSame('name', $result['default_sort']);
        $this->assertSame('asc', $result['default_sort_order']);
        $this->assertSame(280, $result['thumbnail_size']);
        $this->assertTrue($result['show_filename']);
        $this->assertTrue($result['show_rating_overlay']);
        $this->assertTrue($result['show_color_overlay']);
        $this->assertSame('auto', $result['grid_columns']);
        $this->assertFalse($result['enable_pick_ui']);
    }

    public function testGetSettingsReturnsStoredValues(): void
    {
        $stored = [
            'default_sort'       => 'mtime',
            'default_sort_order' => 'desc',
            'thumbnail_size'     => '400',
            'show_filename'      => '0',
            'show_rating_overlay' => '1',
            'show_color_overlay' => 'yes',
            'grid_columns'       => '4',
            'enable_pick_ui'     => '1',
        ];

        $this->config->method('getUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $default) use ($stored) {
                return $stored[$key] ?? $default;
            });

        $result = $this->settings->getSettings(self::USER_ID);

        $this->assertSame('mtime', $result['default_sort']);
        $this->assertSame('desc', $result['default_sort_order']);
        $this->assertSame(400, $result['thumbnail_size']);
        $this->assertFalse($result['show_filename']);
        $this->assertTrue($result['show_rating_overlay']);
        $this->assertTrue($result['show_color_overlay']);
        $this->assertSame('4', $result['grid_columns']);
        $this->assertTrue($result['enable_pick_ui']);
    }

    public function testGetSettingsCastsThumbnailSizeToInt(): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $default) {
                return $key === 'thumbnail_size' ? '350' : $default;
            });

        $result = $this->settings->getSettings(self::USER_ID);
        $this->assertIsInt($result['thumbnail_size']);
        $this->assertSame(350, $result['thumbnail_size']);
    }

    /** @dataProvider booleanParsingProvider */
    public function testGetSettingsBooleanParsing(string $stored, bool $expected): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $default) use ($stored) {
                return $key === 'show_filename' ? $stored : $default;
            });

        $result = $this->settings->getSettings(self::USER_ID);
        $this->assertSame($expected, $result['show_filename']);
    }

    public static function booleanParsingProvider(): array
    {
        return [
            'true string'  => ['true', true],
            'yes string'   => ['yes', true],
            'one string'   => ['1', true],
            'false string' => ['false', false],
            'no string'    => ['no', false],
            'zero string'  => ['0', false],
            'empty string' => ['', false],
            'TRUE uppercase' => ['TRUE', false],  // getBool is case-sensitive
            'Yes mixed'      => ['Yes', false],
            'random string'  => ['banana', false],
        ];
    }

    public function testGetSettingsBooleanDefaultWhenNull(): void
    {
        // getBool returns default when config returns null
        $this->config->method('getUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $default) {
                return $default; // null for getBool, string for get
            });

        $result = $this->settings->getSettings(self::USER_ID);
        // show_filename default is true
        $this->assertTrue($result['show_filename']);
        // enable_pick_ui default is false
        $this->assertFalse($result['enable_pick_ui']);
    }

    // ─── saveSettings ────────────────────────────────────────────────────────

    public function testSaveSettingsStoresValidSort(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with(self::USER_ID, 'starrate', 'default_sort', 'mtime');

        $this->settings->saveSettings(self::USER_ID, ['default_sort' => 'mtime']);
    }

    public function testSaveSettingsStoresValidSortOrder(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with(self::USER_ID, 'starrate', 'default_sort_order', 'desc');

        $this->settings->saveSettings(self::USER_ID, ['default_sort_order' => 'desc']);
    }

    public function testSaveSettingsStoresValidThumbnailSize(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with(self::USER_ID, 'starrate', 'thumbnail_size', '300');

        $this->settings->saveSettings(self::USER_ID, ['thumbnail_size' => 300]);
    }

    public function testSaveSettingsStoresValidGridColumns(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with(self::USER_ID, 'starrate', 'grid_columns', '6');

        $this->settings->saveSettings(self::USER_ID, ['grid_columns' => '6']);
    }

    public function testSaveSettingsBooleanAsString(): void
    {
        $saved = [];
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $val) use (&$saved) {
                $saved[$key] = $val;
            });

        $this->settings->saveSettings(self::USER_ID, [
            'show_filename' => true,
            'show_rating_overlay' => false,
        ]);

        $this->assertSame('1', $saved['show_filename']);
        $this->assertSame('0', $saved['show_rating_overlay']);
    }

    public function testSaveSettingsMultipleKeysAtOnce(): void
    {
        $saved = [];
        $this->config->method('setUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $val) use (&$saved) {
                $saved[$key] = $val;
            });

        $this->settings->saveSettings(self::USER_ID, [
            'default_sort' => 'size',
            'thumbnail_size' => 500,
            'grid_columns' => '3',
        ]);

        $this->assertSame('size', $saved['default_sort']);
        $this->assertSame('500', $saved['thumbnail_size']);
        $this->assertSame('3', $saved['grid_columns']);
    }

    // ─── saveSettings – Validation Errors ────────────────────────────────────

    public function testSaveSettingsThrowsForUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['unknown_key' => 'val']);
    }

    public function testSaveSettingsThrowsForInvalidSort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['default_sort' => 'random']);
    }

    public function testSaveSettingsThrowsForInvalidSortOrder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['default_sort_order' => 'up']);
    }

    public function testSaveSettingsThrowsForThumbnailSizeTooSmall(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['thumbnail_size' => 50]);
    }

    public function testSaveSettingsThrowsForThumbnailSizeTooLarge(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['thumbnail_size' => 1000]);
    }

    public function testSaveSettingsThrowsForInvalidGridColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['grid_columns' => '7']);
    }

    public function testSaveSettingsThrowsForGridColumnsOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->saveSettings(self::USER_ID, ['grid_columns' => '1']);
    }

    public function testSaveSettingsEmptyArrayIsNoop(): void
    {
        $this->config->expects($this->never())->method('setUserValue');
        $this->settings->saveSettings(self::USER_ID, []);
    }

    /** @dataProvider validSortProvider */
    public function testSaveSettingsAcceptsAllValidSorts(string $sort): void
    {
        $this->config->expects($this->once())->method('setUserValue');
        $this->settings->saveSettings(self::USER_ID, ['default_sort' => $sort]);
    }

    public static function validSortProvider(): array
    {
        return [['name'], ['mtime'], ['size']];
    }

    /** @dataProvider validGridColumnsProvider */
    public function testSaveSettingsAcceptsAllValidGridColumns(string $cols): void
    {
        $this->config->expects($this->once())->method('setUserValue');
        $this->settings->saveSettings(self::USER_ID, ['grid_columns' => $cols]);
    }

    public static function validGridColumnsProvider(): array
    {
        return [['auto'], ['2'], ['3'], ['4'], ['5'], ['6'], ['8']];
    }

    /** @dataProvider thumbnailBoundaryProvider */
    public function testSaveSettingsThumbnailSizeBoundaries(int $size, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(\InvalidArgumentException::class);
        } else {
            $this->config->expects($this->once())->method('setUserValue');
        }
        $this->settings->saveSettings(self::USER_ID, ['thumbnail_size' => $size]);
    }

    public static function thumbnailBoundaryProvider(): array
    {
        return [
            'min valid'     => [120, true],
            'max valid'     => [600, true],
            'mid valid'     => [350, true],
            'below min'     => [119, false],
            'above max'     => [601, false],
        ];
    }

    // ─── getSection / getPriority ────────────────────────────────────────────

    public function testGetSectionReturnsStarrate(): void
    {
        $this->assertSame('starrate', $this->settings->getSection());
    }

    public function testGetPriorityReturns50(): void
    {
        $this->assertSame(50, $this->settings->getPriority());
    }

    // ─── getForm ─────────────────────────────────────────────────────────────

    public function testGetFormReturnsTemplateResponse(): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(fn($uid, $app, $key, $default) => $default);

        $response = $this->settings->getForm();

        $this->assertInstanceOf(\OCP\AppFramework\Http\TemplateResponse::class, $response);
        $this->assertSame('settings/personal', $response->getTemplateName());
    }

    public function testGetFormPassesSettingsAsParams(): void
    {
        $this->config->method('getUserValue')
            ->willReturnCallback(fn($uid, $app, $key, $default) => $default);

        $response = $this->settings->getForm();
        $params = $response->getParams();

        $this->assertArrayHasKey('settings', $params);
        $this->assertArrayHasKey('default_sort', $params['settings']);
        $this->assertArrayHasKey('thumbnail_size', $params['settings']);
        $this->assertArrayHasKey('enable_pick_ui', $params['settings']);
    }
}

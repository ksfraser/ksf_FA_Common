<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Traits;

use Ksfraser\Frontaccounting\Common\Tests\Unit\FaHookStub;
use ksfraser\FrontAccounting\Common\Preference\PreferenceHookContract;
use ksfraser\FrontAccounting\Common\Preference\PreferenceRepository;
use ksfraser\FrontAccounting\Common\Traits\ModulePreferencesTrait;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../App/FaHookStub.php';

/**
 * ModulePreferencesTraitTest - verifies the trait binds module name and user
 * once so call sites can read `$this->pref()` / `$this->setPref()`.
 *
 * FA function behaviour is stubbed the same way the rest of the suite does it:
 * `hook_invoke_all` via tests/Unit/App/FaHookStub.php. There is deliberately no
 * `db_query` stub, so the repository's no-database fallback is what runs and
 * the assertions observe the payload the repository hands to the hook.
 *
 * @package ksfraser\FrontAccounting\Common\Tests\Unit\Traits
 * @since   2.2.0
 */
final class ModulePreferencesTraitTest extends TestCase
{
    /** @var array|null */
    private $originalSession;

    /** @var object|null Class using the trait */
    private $hooksClass;

    protected function setUp(): void
    {
        parent::setUp();
        FaHookStub::reset();
        $this->originalSession = isset($_SESSION) ? $_SESSION : null;
        $_SESSION = [];

        // Stands in for `class hooks_ksf_FA_Calendar extends hooks`, which
        // always carries `var $module_name`.
        $this->hooksClass = new class {
            use ModulePreferencesTrait;

            /** @var string FA hooks convention */
            public $module_name = 'ksf_FA_Calendar';

            public function callPref(string $key, $default = null)
            {
                return $this->pref($key, $default);
            }

            public function callSetPref(string $key, $value): bool
            {
                return $this->setPref($key, $value);
            }

            public function callModule(): string
            {
                return $this->preferenceModule();
            }

            public function callUser(): string
            {
                return $this->preferenceUser();
            }

            public function callRepository(): PreferenceRepository
            {
                return $this->modulePreferences();
            }

            public function callSetModule(?string $name): void
            {
                $this->setPreferenceModule($name);
            }

            public function callSetUser($userId): void
            {
                $this->setPreferenceUser($userId);
            }

            public function callSetTable(?string $table): void
            {
                $this->setPreferenceTable($table);
            }
        };
    }

    protected function tearDown(): void
    {
        FaHookStub::reset();
        if ($this->originalSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->originalSession;
        }
        $this->hooksClass = null;
        parent::tearDown();
    }

    public function testModuleNameComesFromUsingClassProperty(): void
    {
        $this->assertSame('ksf_FA_Calendar', $this->hooksClass->callModule());
    }

    public function testGetPassesUsingClassModuleNameAndSessionUserToRepository(): void
    {
        $_SESSION['user_id'] = 7;
        $seen = null;
        FaHookStub::respondTo(PreferenceHookContract::HOOK_GET, function (array &$data) use (&$seen): void {
            $seen = $data;
            $data[PreferenceHookContract::KEY_HANDLED] = true;
            $data[PreferenceHookContract::KEY_VALUE] = 'week';
        });

        $this->assertSame('week', $this->hooksClass->callPref('default_view', 'month'));

        $this->assertIsArray($seen);
        $this->assertSame('ksf_FA_Calendar', $seen[PreferenceHookContract::KEY_MODULE]);
        $this->assertSame('7', $seen[PreferenceHookContract::KEY_USER]);
        $this->assertSame('default_view', $seen[PreferenceHookContract::KEY_NAME]);
    }

    public function testSetPassesUsingClassModuleNameToRepository(): void
    {
        $_SESSION['user_id'] = '3';
        $seen = null;
        FaHookStub::respondTo(PreferenceHookContract::HOOK_SET, function (array &$data) use (&$seen): void {
            $seen = $data;
            $data[PreferenceHookContract::KEY_HANDLED] = true;
        });

        $this->assertTrue($this->hooksClass->callSetPref('default_view', ['col' => 3]));

        $this->assertIsArray($seen);
        $this->assertSame('ksf_FA_Calendar', $seen[PreferenceHookContract::KEY_MODULE]);
        $this->assertSame('3', $seen[PreferenceHookContract::KEY_USER]);
        $this->assertSame(['col' => 3], $seen[PreferenceHookContract::KEY_VALUE]);
    }

    public function testExplicitModuleOverrideBeatsUsingClassProperty(): void
    {
        $this->hooksClass->callSetModule('ksf_FA_CRM');

        $this->assertSame('ksf_FA_CRM', $this->hooksClass->callModule());

        $seen = null;
        FaHookStub::respondTo(PreferenceHookContract::HOOK_GET, function (array &$data) use (&$seen): void {
            $seen = $data;
        });
        $this->hooksClass->callPref('default_view');

        $this->assertSame('ksf_FA_CRM', $seen[PreferenceHookContract::KEY_MODULE]);
    }

    public function testModuleFallsBackToUnknownWhenNeitherSourceAvailable(): void
    {
        $nameless = new class {
            use ModulePreferencesTrait;

            public function callModule(): string
            {
                return $this->preferenceModule();
            }

            public function callPref(string $key, $default = null)
            {
                return $this->pref($key, $default);
            }
        };

        $this->assertSame('unknown', $nameless->callModule());
    }

    public function testUserIdComesFromSessionWhenAvailable(): void
    {
        $_SESSION['user_id'] = 11;

        $this->assertSame('11', $this->hooksClass->callUser());
    }

    public function testExplicitUserOverrideBeatsSession(): void
    {
        $_SESSION['user_id'] = 11;
        $this->hooksClass->callSetUser(42);

        $this->assertSame('42', $this->hooksClass->callUser());
    }

    public function testUserFallsBackToEmptyStringOutsideASession(): void
    {
        $this->assertSame('', $this->hooksClass->callUser());
    }

    public function testGetReturnsDefaultWhenNoRowExists(): void
    {
        $_SESSION['user_id'] = 1;

        $this->assertSame('month', $this->hooksClass->callPref('default_view', 'month'));
        $this->assertNull($this->hooksClass->callPref('default_view'));
    }

    public function testSetReturnsBoolWithoutDatabase(): void
    {
        $_SESSION['user_id'] = 1;

        $result = $this->hooksClass->callSetPref('default_view', 'week');

        $this->assertIsBool($result);
        $this->assertFalse($result);
    }

    public function testRepositoryIsBuiltLazilyAndReused(): void
    {
        $first = $this->hooksClass->callRepository();

        $this->assertInstanceOf(PreferenceRepository::class, $first);
        $this->assertSame($first, $this->hooksClass->callRepository());
    }

    public function testRepositoryUsesTheConventionDefaultTable(): void
    {
        $_SESSION['user_id'] = 1;
        FaHookStub::reset();

        // Constructing the repository performs no DB work, so it is safe to
        // call before any FA bootstrap.
        $this->assertSame(
            PreferenceRepository::DEFAULT_TABLE,
            $this->hooksClass->callRepository()->tableName()
        );
    }

    public function testConstructingTraitUserPerformsNoDbWork(): void
    {
        // No session, no DB, no hooks: construction must not touch any of them.
        $fresh = new class {
            use ModulePreferencesTrait;

            public $module_name = 'ksf_FA_HRM';

            public function callModule(): string
            {
                return $this->preferenceModule();
            }
        };

        $this->assertIsObject($fresh);
        $this->assertSame('ksf_FA_HRM', $fresh->callModule());
    }

    public function testTableOverrideIsHonoured(): void
    {
        $_SESSION['user_id'] = 1;
        $this->hooksClass->callSetTable('0_ksf_custom_prefs');

        $this->assertSame('0_ksf_custom_prefs', $this->hooksClass->callRepository()->tableName());
    }
}

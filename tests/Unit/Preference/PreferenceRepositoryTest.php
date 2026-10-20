<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Preference;

use Ksfraser\Frontaccounting\Common\Tests\Unit\FaHookStub;
use ksfraser\FrontAccounting\Common\Preference\PreferenceHookContract;
use ksfraser\FrontAccounting\Common\Preference\PreferenceRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../App/FaHookStub.php';

if (!defined('TB_PREF')) {
    // FA's company prefix. Defining it here exercises the prefixing branch of
    // resolvedTableName() for real; it is the value company 0 runs with.
    define('TB_PREF', '0_');
}

final class PreferenceRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FaHookStub::reset();
    }

    protected function tearDown(): void
    {
        FaHookStub::reset();
        parent::tearDown();
    }

    public function testHookContractDefinesExpectedKeys(): void
    {
        $this->assertSame('ksf_preference_get', PreferenceHookContract::HOOK_GET);
        $this->assertSame('ksf_preference_set', PreferenceHookContract::HOOK_SET);
        $this->assertSame('module_name', PreferenceHookContract::KEY_MODULE);
        $this->assertSame('user_id', PreferenceHookContract::KEY_USER);
        $this->assertSame('pref_key', PreferenceHookContract::KEY_NAME);
        $this->assertSame('pref_value', PreferenceHookContract::KEY_VALUE);
    }

    public function testDefaultTableNameIsConventionCompliant(): void
    {
        $this->assertSame('0_ksf_module_prefs', PreferenceRepository::DEFAULT_TABLE);

        // AGENTS_ARCH.md section 12: 0_ is the COMPANY prefix, so an `fa`
        // second segment would collide with FA core's own 0_fa_* tables.
        $segments = explode('_', PreferenceRepository::DEFAULT_TABLE);
        $this->assertSame('0', $segments[0], 'First segment must be the company prefix marker');
        $this->assertSame('ksf', $segments[1], 'Second segment must be the vendor segment');
        $this->assertStringStartsNotWith('0_fa_', PreferenceRepository::DEFAULT_TABLE);
    }

    public function testDefaultRepositoryResolvesToSharedPrefsTable(): void
    {
        $repo = new PreferenceRepository();

        $this->assertSame('0_ksf_module_prefs', $repo->tableName());
    }

    public function testDefaultRepositoryIsNotTheLegacyFaPrefixedTable(): void
    {
        $repo = new PreferenceRepository();

        $this->assertNotSame('fa_preference_values', $repo->tableName());
        $this->assertStringStartsNotWith('0_fa_', $repo->tableName());
    }

    public function testExplicitTableNameIsHonoured(): void
    {
        $this->assertSame('0_ksf_custom_prefs', (new PreferenceRepository('0_ksf_custom_prefs'))->tableName());
    }

    public function testExplicitBareTableNameGetsCompanyPrefix(): void
    {
        $this->assertSame('0_ksf_custom_prefs', (new PreferenceRepository('ksf_custom_prefs'))->tableName());
    }

    public function testAlreadyResolvedPrefixIsNotDoubled(): void
    {
        $repo = new PreferenceRepository(TB_PREF . 'ksf_module_prefs');

        $this->assertSame('0_ksf_module_prefs', $repo->tableName());
    }

    public function testRepositoryFallsBackWhenDbAndHooksUnavailable(): void
    {
        $repo = new PreferenceRepository('fa_preference_values');

        $this->assertSame('fallback', $repo->get('ksf_FA_Calendar', '1', 'unknown_key', 'fallback'));
        $this->assertFalse($repo->set('ksf_FA_Calendar', '1', 'unknown_key', ['x' => 1]));
    }

    public function testGetReturnsDefaultWhenNoRowExists(): void
    {
        // No row, no DB: an unhandled hook leaves HANDLED false and the read
        // path yields the caller's default.
        $repo = new PreferenceRepository();

        $this->assertSame('month', $repo->get('ksf_FA_Calendar', '1', 'default_view', 'month'));
        $this->assertNull($repo->get('ksf_FA_Calendar', '1', 'default_view'));
    }

    public function testGetReturnsStoredValueFromHookHandler(): void
    {
        FaHookStub::respondTo(PreferenceHookContract::HOOK_GET, function (array &$data): void {
            $data[PreferenceHookContract::KEY_HANDLED] = true;
            $data[PreferenceHookContract::KEY_VALUE] = 'week';
        });

        $repo = new PreferenceRepository();

        $this->assertSame('week', $repo->get('ksf_FA_Calendar', '1', 'default_view', 'month'));
    }

    public function testGetPassesKeySegmentsToHookHandler(): void
    {
        $seen = null;
        FaHookStub::respondTo(PreferenceHookContract::HOOK_GET, function (array &$data) use (&$seen): void {
            $seen = $data;
            $data[PreferenceHookContract::KEY_HANDLED] = true;
            $data[PreferenceHookContract::KEY_VALUE] = 'stored';
        });

        $repo = new PreferenceRepository();
        $repo->get('ksf_FA_Calendar', '7', 'default_view', 'month');

        $this->assertIsArray($seen);
        $this->assertSame('ksf_FA_Calendar', $seen[PreferenceHookContract::KEY_MODULE]);
        $this->assertSame('7', $seen[PreferenceHookContract::KEY_USER]);
        $this->assertSame('default_view', $seen[PreferenceHookContract::KEY_NAME]);
        $this->assertSame('month', $seen[PreferenceHookContract::KEY_DEFAULT]);
    }

    public function testSetReturnsBool(): void
    {
        $repo = new PreferenceRepository();

        // No DB available -> the write is declined, but the contract is bool.
        $result = $repo->set('ksf_FA_Calendar', '1', 'default_view', 'week');

        $this->assertIsBool($result);
        $this->assertFalse($result);
    }

    public function testSetReturnsTrueWhenHookHandlerTakesTheWrite(): void
    {
        FaHookStub::respondTo(PreferenceHookContract::HOOK_SET, function (array &$data): void {
            $data[PreferenceHookContract::KEY_HANDLED] = true;
        });

        $repo = new PreferenceRepository();

        $this->assertTrue($repo->set('ksf_FA_Calendar', '1', 'default_view', ['nested' => true]));
    }

    public function testSetPassesKeySegmentsToHookHandler(): void
    {
        $seen = null;
        FaHookStub::respondTo(PreferenceHookContract::HOOK_SET, function (array &$data) use (&$seen): void {
            $seen = $data;
            $data[PreferenceHookContract::KEY_HANDLED] = true;
        });

        $repo = new PreferenceRepository();
        $repo->set('ksf_FA_Calendar', '7', 'default_view', 'week');

        $this->assertIsArray($seen);
        $this->assertSame('ksf_FA_Calendar', $seen[PreferenceHookContract::KEY_MODULE]);
        $this->assertSame('7', $seen[PreferenceHookContract::KEY_USER]);
        $this->assertSame('default_view', $seen[PreferenceHookContract::KEY_NAME]);
        $this->assertSame('week', $seen[PreferenceHookContract::KEY_VALUE]);
    }
}

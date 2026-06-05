<?php

declare(strict_types=1);

namespace KsfCommon\Tests\Unit\Preference;

use KsfCommon\Preference\PreferenceHookContract;
use KsfCommon\Preference\PreferenceRepository;
use PHPUnit\Framework\TestCase;

final class PreferenceRepositoryTest extends TestCase
{
    public function testHookContractDefinesExpectedKeys(): void
    {
        $this->assertSame('ksf_preference_get', PreferenceHookContract::HOOK_GET);
        $this->assertSame('ksf_preference_set', PreferenceHookContract::HOOK_SET);
        $this->assertSame('module_name', PreferenceHookContract::KEY_MODULE);
        $this->assertSame('user_id', PreferenceHookContract::KEY_USER);
        $this->assertSame('pref_key', PreferenceHookContract::KEY_NAME);
        $this->assertSame('pref_value', PreferenceHookContract::KEY_VALUE);
    }

    public function testRepositoryFallsBackWhenDbAndHooksUnavailable(): void
    {
        $repo = new PreferenceRepository('fa_preference_values');

        $this->assertSame('fallback', $repo->get('ksf_FA_Calendar', '1', 'unknown_key', 'fallback'));
        $this->assertFalse($repo->set('ksf_FA_Calendar', '1', 'unknown_key', ['x' => 1]));
    }
}

<?php

declare(strict_types=1);

namespace KsfCommon\Preference;

final class PreferenceHookContract
{
    public const HOOK_GET = 'ksf_preference_get';
    public const HOOK_SET = 'ksf_preference_set';

    public const KEY_MODULE = 'module_name';
    public const KEY_USER = 'user_id';
    public const KEY_NAME = 'pref_key';
    public const KEY_VALUE = 'pref_value';
    public const KEY_DEFAULT = 'default_value';
    public const KEY_HANDLED = 'handled';

    private function __construct()
    {
    }
}

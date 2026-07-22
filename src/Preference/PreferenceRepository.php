<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Preference;

final class PreferenceRepository
{
    private $tableName;

    public function __construct(string $tableName = 'fa_preference_values')
    {
        $this->tableName = $tableName;
    }

    public function get(string $moduleName, string $userId, string $key, $default = null)
    {
        $payload = array(
            PreferenceHookContract::KEY_MODULE => $moduleName,
            PreferenceHookContract::KEY_USER => $userId,
            PreferenceHookContract::KEY_NAME => $key,
            PreferenceHookContract::KEY_DEFAULT => $default,
            PreferenceHookContract::KEY_HANDLED => false,
        );

        if (function_exists('hook_invoke_all')) {
            hook_invoke_all(PreferenceHookContract::HOOK_GET, $payload);
            if (!empty($payload[PreferenceHookContract::KEY_HANDLED])) {
                return $payload[PreferenceHookContract::KEY_VALUE] ?? $default;
            }
        }

        return $this->readFromDatabase($moduleName, $userId, $key, $default);
    }

    public function set(string $moduleName, string $userId, string $key, $value): bool
    {
        $payload = array(
            PreferenceHookContract::KEY_MODULE => $moduleName,
            PreferenceHookContract::KEY_USER => $userId,
            PreferenceHookContract::KEY_NAME => $key,
            PreferenceHookContract::KEY_VALUE => $value,
            PreferenceHookContract::KEY_HANDLED => false,
        );

        if (function_exists('hook_invoke_all')) {
            hook_invoke_all(PreferenceHookContract::HOOK_SET, $payload);
            if (!empty($payload[PreferenceHookContract::KEY_HANDLED])) {
                return true;
            }
        }

        return $this->writeToDatabase($moduleName, $userId, $key, $value);
    }

    private function readFromDatabase(string $moduleName, string $userId, string $key, $default)
    {
        if (!function_exists('db_query')) {
            return $default;
        }

        $sql = sprintf(
            "SELECT pref_value FROM %s WHERE module_name=%s AND user_id=%s AND pref_key=%s LIMIT 1",
            $this->resolvedTableName(),
            db_escape($moduleName),
            db_escape($userId),
            db_escape($key)
        );
        $result = db_query($sql, 'Failed to read preference');
        if ($result && function_exists('db_fetch_row')) {
            $row = db_fetch_row($result);
            if ($row !== false && isset($row[0])) {
                return $this->decodeValue((string) $row[0]);
            }
        }

        return $default;
    }

    private function writeToDatabase(string $moduleName, string $userId, string $key, $value): bool
    {
        if (!function_exists('db_query')) {
            return false;
        }

        $encoded = $this->encodeValue($value);
        $sql = sprintf(
            "INSERT INTO %s (module_name, user_id, pref_key, pref_value) VALUES (%s, %s, %s, %s) ON DUPLICATE KEY UPDATE pref_value=VALUES(pref_value)",
            $this->resolvedTableName(),
            db_escape($moduleName),
            db_escape($userId),
            db_escape($key),
            db_escape($encoded)
        );
        db_query($sql, 'Failed to save preference');
        return true;
    }

    private function encodeValue($value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function decodeValue(string $value)
    {
        $decoded = json_decode($value, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
    }

    private function resolvedTableName(): string
    {
        if (defined('TB_PREF') && strpos($this->tableName, TB_PREF) !== 0) {
            return TB_PREF . $this->tableName;
        }

        return $this->tableName;
    }
}

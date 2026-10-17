<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Preference;

/**
 * Shared module key/value preference store.
 *
 * One row per (module_name, user_id, pref_key), stored in the single
 * cross-module table `0_ksf_module_prefs` (see AGENTS_ARCH.md section 12).
 * The previous default, `fa_preference_values`, resolved to `0_fa_*`, which
 * collides with FA core's own table namespace because `0_` is the COMPANY
 * prefix, not a vendor segment.
 *
 * @package KSF\Common\Preference
 * @since   1.0.0
 */
final class PreferenceRepository
{
    /**
     * Convention-compliant default table (company-prefixed).
     *
     * @var string
     * @since 1.1.0
     */
    public const DEFAULT_TABLE = '0_ksf_module_prefs';

    /** @var string */
    private $tableName;

    /**
     * @param string $tableName Table name; defaults to DEFAULT_TABLE. May be
     *                          given bare (`ksf_module_prefs`), with FA's
     *                          install-engine company prefix (`0_ksf_module_prefs`),
     *                          or already resolved (`0_ksf_module_prefs`).
     *
     * @since 1.0.0
     */
    public function __construct(string $tableName = self::DEFAULT_TABLE)
    {
        $this->tableName = $tableName;
    }

    /**
     * Read a preference, falling back to $default when no row exists.
     *
     * @param string $moduleName Owning module name (key segment)
     * @param string $userId     FA user id, or '' for a company-wide setting
     * @param string $key        Preference key
     * @param mixed  $default    Value returned when unset
     * @return mixed Stored value (JSON-decoded) or $default
     *
     * @since 1.0.0
     */
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

    /**
     * Write a preference (upsert on the UNIQUE(module_name, user_id, pref_key) key).
     *
     * @param string $moduleName Owning module name (key segment)
     * @param string $userId     FA user id, or '' for a company-wide setting
     * @param string $key        Preference key
     * @param mixed  $value      Value to JSON-encode and store
     * @return bool True when the write was dispatched or executed
     *
     * @since 1.0.0
     */
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

    /**
     * The table this repository reads and writes, with the company prefix applied.
     *
     * @return string e.g. `0_ksf_module_prefs` for company 0
     *
     * @since 1.1.0
     */
    public function tableName(): string
    {
        return $this->resolvedTableName();
    }

    /**
     * Apply the runtime company prefix (TB_PREF) to the configured table name.
     *
     * Accepts all three spellings a caller may reasonably pass:
     *   - already resolved (`0_ksf_module_prefs`) -> unchanged
     *   - FA install-engine convention (`0_ksf_module_prefs`, `0_` replaced by
     *     the real prefix, exactly as db_import() does) -> `0_ksf_module_prefs`
     *   - bare (`ksf_module_prefs`) -> `0_ksf_module_prefs`
     *
     * Outside FA (no TB_PREF defined) the configured name is used verbatim so
     * standalone callers and tests still see a deterministic string.
     *
     * @return string
     *
     * @since 1.0.0
     */
    private function resolvedTableName(): string
    {
        if (!defined('TB_PREF')) {
            return $this->tableName;
        }

        if (strpos($this->tableName, TB_PREF) === 0) {
            return $this->tableName;
        }

        // Mirror FA's db_import(): a literal `0_` company prefix is replaced,
        // never concatenated.
        if (strpos($this->tableName, '0_') === 0) {
            return TB_PREF . substr($this->tableName, 2);
        }

        return TB_PREF . $this->tableName;
    }
}

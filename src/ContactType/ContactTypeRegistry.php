<?php
/**
 * ContactTypeRegistry — central registry for all platform-level contact types
 * across the KSF ecosystem.
 *
 * Types are persisted in the `ksf_contact_types` DB table (created by
 * ksf_FA_Common's install.sql).  Each module registers its types during
 * activate_extension() and cleans up during deactivate_extension().
 *
 * This is a PLATFORM concept (not calendar-specific).  The Calendar module
 * consumes these types; RBAC, HRM, CRM, Assets, and Projects define them.
 *
 * Read path (no runtime hooks):
 *   ContactTypeRegistry::getTypes()
 *     → SELECT * FROM ksf_contact_types
 *     → static cache (per request)
 *     → fallback: built-in defaults if table missing/empty
 *
 * Write path (activation only):
 *   Module::activate_extension()
 *     → ContactTypeRegistry::registerTypes([...])
 *     → INSERT IGNORE INTO ksf_contact_types
 *
 * @package KsfCommon\ContactType
 */

declare(strict_types=1);

namespace KsfCommon\ContactType;

class ContactTypeRegistry
{
    /** @var string DB table name (without TB_PREF) */
    private const TABLE_NAME = 'ksf_contact_types';

    /** @var ContactType[]|null  Per-request static cache */
    private static ?array $types = null;

    /**
     * Return all registered contact types, keyed by name.
     *
     * Reads from the DB table (with per-request caching).  Falls back to
     * built-in defaults if the table does not exist.
     *
     * @return ContactType[]
     */
    public static function getTypes(): array
    {
        if (self::$types !== null) {
            return self::$types;
        }

        self::$types = self::loadFromDb();

        if (empty(self::$types)) {
            self::$types = self::getDefaultTypes();
        }

        return self::$types;
    }

    /**
     * Return a single type by name, or null if not registered.
     *
     * @param string $name
     * @return ContactType|null
     */
    public static function getType(string $name): ?ContactType
    {
        $types = self::getTypes();
        return $types[$name] ?? null;
    }

    /**
     * Return plain string names of all registered types.
     *
     * @return string[]
     */
    public static function getTypeNames(): array
    {
        return array_keys(self::getTypes());
    }

    /**
     * Check whether a given type name is registered.
     *
     * @param string $name
     * @return bool
     */
    public static function isValidType(string $name): bool
    {
        return isset(self::getTypes()[$name]);
    }

    /**
     * Return type definitions for JSON API responses.
     *
     * @return array<int, array{name: string, label: string, module: string, description: string|null}>
     */
    public static function getTypeDefinitions(): array
    {
        return array_map(function (ContactType $t): array {
            return $t->toArray();
        }, array_values(self::getTypes()));
    }

    /**
     * Register one or more contact types (called during module activation).
     *
     * Idempotent — INSERT IGNORE so re-activation does not error.  First
     * registration wins (modules activated earlier take priority).
     *
     * @param ContactType[] $types
     * @return void
     */
    public static function registerTypes(array $types): void
    {
        if (empty($types) || !function_exists('db_query')) {
            return;
        }

        $prefix = defined('TB_PREF') ? TB_PREF : '';

        foreach ($types as $type) {
            if (!$type instanceof ContactType) {
                continue;
            }
            $sql = "INSERT IGNORE INTO `{$prefix}" . self::TABLE_NAME . "`
                    (`name`, `label`, `module`, `description`)
                    VALUES (" . db_escape($type->getName()) . ", "
                           . db_escape($type->getLabel()) . ", "
                           . db_escape($type->getModule()) . ", "
                           . ($type->getDescription() !== null
                               ? db_escape($type->getDescription())
                               : 'NULL') . ")";
            db_query($sql, 'Could not register contact type: ' . $type->getName());
        }

        self::$types = null;
    }

    /**
     * Remove all contact types owned by a given module
     * (called during module deactivation).
     *
     * @param string $module Module identifier (e.g. 'ksf_CRM')
     * @return void
     */
    public static function unregisterModule(string $module): void
    {
        if ($module === '' || !function_exists('db_query')) {
            return;
        }

        $prefix = defined('TB_PREF') ? TB_PREF : '';
        $sql    = "DELETE FROM `{$prefix}" . self::TABLE_NAME . "` WHERE module = " . db_escape($module);
        db_query($sql, 'Could not unregister contact types for module: ' . $module);

        self::$types = null;
    }

    /**
     * Force a reload on the next getTypes() call.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$types = null;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Query the DB for all registered types.
     *
     * Returns empty array on any error (table not found, not in FA context).
     *
     * @return ContactType[]  Keyed by name.
     */
    private static function loadFromDb(): array
    {
        if (!function_exists('db_query')) {
            return [];
        }

        try {
            $prefix = defined('TB_PREF') ? TB_PREF : '';
            $result = db_query(
                "SELECT `name`, `label`, `module`, `description`
                 FROM `{$prefix}" . self::TABLE_NAME . "`
                 ORDER BY `name` ASC",
                null
            );

            if (!$result) {
                return [];
            }

            $types = [];
            while ($row = db_fetch_assoc($result)) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $types[$name] = new ContactType(
                    $name,
                    (string) ($row['label']       ?? $name),
                    (string) ($row['module']      ?? ''),
                    isset($row['description']) ? (string) $row['description'] : null
                );
            }

            return $types;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Built-in defaults when the DB table is not available.
     *
     * ksf_FA_Common always defines the base User type.  RBAC, CRM, HRM,
     * Assets, and Project modules extend this set during activation.
     *
     * @return ContactType[]  Keyed by name.
     */
    private static function getDefaultTypes(): array
    {
        return [
            'fa_user'     => new ContactType(
                'fa_user', 'FA User', 'ksf_FA_Common',
                'FrontAccounting RBAC user account'
            ),
            'crm_contact' => new ContactType(
                'crm_contact', 'CRM Contact', 'ksf_FA_Common',
                'Customer or lead managed by the CRM module'
            ),
            'resource'    => new ContactType(
                'resource', 'Resource', 'ksf_FA_Common',
                'Shared resource (room, equipment, vehicle)'
            ),
            'ad_hoc'      => new ContactType(
                'ad_hoc', 'Ad-hoc', 'ksf_FA_Common',
                'External invitee without a system record'
            ),
        ];
    }
}

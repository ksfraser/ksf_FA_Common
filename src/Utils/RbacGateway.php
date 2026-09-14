<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Utils;

/**
 * RbacGateway - Centralized RBAC authorization entry point.
 *
 * All modules should use this class to check authorization rather than
 * calling hook_invoke_all directly. This provides a consistent interface
 * and handles the case where RBAC module is not installed.
 *
 * Usage:
 *   $result = RbacGateway::checkAccess('view', 'customer', 5);
 *   if ($result === RbacGateway::ALLOW) { ... }
 *
 * View-based column filtering (requires ksf-db-security package):
 *   use ksfraser\DbSecurity\Column\ColumnPermissions;
 *
 *   $def = (new ColumnPermissions('customer'))
 *       ->role('admin')->grantAll()
 *       ->role('clerk')->columns(['id', 'name']);
 *
 *   $view = RbacGateway::getViewForTable('customer', '0_');
 *   // Returns: 0_customer_mod_clerk_v for clerk role
 *
 * @since 1.5.0
 */
class RbacGateway
{
    /** Access granted */
    public const ALLOW = true;

    /** Access denied */
    public const DENY = false;

    /** No opinion - fall through to native FA permissions */
    public const NOOP = null;

    /** @var array<string, string> Cached view names per table */
    private static $viewCache = [];

    /**
     * Check if an action is allowed.
     *
     * @param string $action Action name (view, edit, delete, create, etc.)
     * @param string $module Module name (customer, debtor_trans, stock, etc.)
     * @param int|null $resourceId Optional record ID for record-level checks
     * @param mixed $resource Optional resource object for additional context
     * @return bool|null ALLOW, DENY, or NOOP
     *
     * @since 1.5.0
     */
    public static function checkAccess(string $action, string $module, ?int $resourceId = null, $resource = null): ?bool
    {
        if (!isset($_SESSION["wa_current_user"])) {
            return self::NOOP;
        }

        $userId = $_SESSION["wa_current_user"]->user ?? 0;

        if ($userId <= 0) {
            return self::NOOP;
        }

        $data = [
            'user_id' => $userId,
            'action' => $action,
            'module' => $module,
            'resource_type' => $module,
            'resource_id' => $resourceId,
            'resource' => $resource,
        ];

        if (!function_exists('hook_invoke_all')) {
            return self::NOOP;
        }

        $result = hook_invoke_all('ksf_FA_RBAC', 'authorize', $data);

        if ($result === true) {
            return self::ALLOW;
        }

        if ($result === false) {
            return self::DENY;
        }

        return self::NOOP;
    }

    /**
     * Filter a record list based on user access.
     *
     * Modifies the SQL query to add WHERE clauses based on RBAC rules.
     *
     * @param string $module Module name
     * @param string $sql Original SQL query (passed by reference)
     * @param array &$params Query parameters (passed by reference)
     * @param string $action Action being performed (default: 'list')
     * @return bool|null True if filter was applied, null if no opinion
     *
     * @since 1.5.0
     */
    public static function filterRecordList(string $module, string &$sql, array &$params = [], string $action = 'list'): ?bool
    {
        if (!isset($_SESSION["wa_current_user"])) {
            return self::NOOP;
        }

        $userId = $_SESSION["wa_current_user"]->user ?? 0;

        if ($userId <= 0) {
            return self::NOOP;
        }

        $data = [
            'user_id' => $userId,
            'module' => $module,
            'action' => $action,
            'sql' => $sql,
            'params' => $params,
        ];

        if (!function_exists('hook_invoke_all')) {
            return self::NOOP;
        }

        $result = hook_invoke_all('ksf_FA_RBAC', 'filterRecordList', $data);

        if (isset($data['sql']) && $data['sql'] !== $sql) {
            $sql = $data['sql'];
            $params = $data['params'] ?? [];
            return self::ALLOW;
        }

        return self::NOOP;
    }

    /**
     * Get the MySQL view name for a table based on user's highest role.
     *
     * Use this to route queries through filtered views:
     *   $view = RbacGateway::getViewForTable('customer');
     *   $sql = "SELECT * FROM {$view} WHERE ...";
     *
     * Falls back to the base table if no filtered view exists.
     *
     * @param string $table Base table name (without prefix)
     * @param string $prefix Table prefix (default: TB_PREF)
     * @return string View name or base table name
     *
     * @since 1.5.0
     */
    public static function getViewForTable(string $table, string $prefix = ''): string
    {
        $cacheKey = $table . '::' . $prefix;
        if (isset(self::$viewCache[$cacheKey])) {
            return self::$viewCache[$cacheKey];
        }

        $role = self::getHighestRole();
        $viewName = self::buildViewName($table, $role, $prefix);

        self::$viewCache[$cacheKey] = $viewName;
        return $viewName;
    }

    /**
     * Check if user can access a specific column.
     *
     * @param string $table
     * @param string $column
     * @return bool
     *
     * @since 1.5.0
     */
    public static function canAccessColumn(string $table, string $column): bool
    {
        $role = self::getHighestRole();
        return self::checkColumnAccess($table, $column, $role);
    }

    /**
     * Filter a SELECT query to only include permitted columns.
     *
     * @param string $sql Original SQL
     * @param string $table Table name
     * @param array $allowedColumns Columns user can access
     * @return string Filtered SQL
     *
     * @since 1.5.0
     */
    public static function filterColumns(string $sql, string $table, array $allowedColumns): string
    {
        if (empty($allowedColumns) || $allowedColumns === ['*']) {
            return $sql;
        }

        if (preg_match('/SELECT\s+(.+?)\s+FROM/is', $sql, $matches)) {
            $currentCols = trim($matches[1]);

            if ($currentCols === '*') {
                $sql = preg_replace(
                    '/SELECT\s+\*\s+FROM/is',
                    'SELECT ' . implode(', ', $allowedColumns) . ' FROM',
                    $sql
                );
            }
        }

        return $sql;
    }

    /**
     * Quick check if user has a specific role.
     *
     * @param string $role Role name (admin, manager, clerk, salesman, etc.)
     * @return bool
     *
     * @since 1.5.0
     */
    public static function hasRole(string $role): bool
    {
        if (!isset($_SESSION["wa_current_user"])) {
            return false;
        }

        $user = $_SESSION["wa_current_user"];

        if (isset($user->user) && $user->user == 1) {
            return $role === 'admin';
        }

        if (isset($user->access) && is_array($user->access)) {
            foreach ($user->access as $area => $level) {
                if ($level > 0) {
                    if ($role === 'admin' && $level >= 99) {
                        return true;
                    }
                    if ($role === 'manager' && $level >= 10) {
                        return true;
                    }
                    if ($role === 'clerk' && $level >= 1) {
                        return true;
                    }
                }
            }
        }

        if ($role === 'salesman' && isset($user->salesman) && !empty($user->salesman)) {
            return true;
        }

        return false;
    }

    /**
     * Get current user ID.
     *
     * @return int
     *
     * @since 1.5.0
     */
    public static function getUserId(): int
    {
        if (!isset($_SESSION["wa_current_user"])) {
            return 0;
        }

        return (int) ($_SESSION["wa_current_user"]->user ?? 0);
    }

    /**
     * Check if RBAC module is available and active.
     *
     * @return bool
     *
     * @since 1.5.0
     */
    public static function isAvailable(): bool
    {
        return function_exists('hook_invoke_all');
    }

    /**
     * Get the user's highest privilege role.
     *
     * @return string Role name
     *
     * @since 1.5.0
     */
    private static function getHighestRole(): string
    {
        if (!isset($_SESSION["wa_current_user"])) {
            return 'viewer';
        }

        $user = $_SESSION["wa_current_user"];

        if (isset($user->user) && $user->user == 1) {
            return 'admin';
        }

        $maxLevel = 0;
        if (isset($user->access) && is_array($user->access)) {
            foreach ($user->access as $level) {
                if ($level > $maxLevel) {
                    $maxLevel = $level;
                }
            }
        }

        if ($maxLevel >= 99) {
            return 'admin';
        }
        if ($maxLevel >= 10) {
            return 'manager';
        }
        if ($maxLevel >= 1) {
            return 'clerk';
        }

        if (isset($user->salesman) && !empty($user->salesman)) {
            return 'salesman';
        }

        return 'viewer';
    }

    /**
     * Build view name for table + role combination.
     *
     * Naming: {table}_mod_{role}_v
     * Example: customer_mod_manager_v
     *
     * This groups views with base table in alphabetical listings.
     */
    private static function buildViewName(string $table, string $role, string $prefix): string
    {
        $viewName = "{$table}_mod_{$role}_v";

        if (!empty($prefix)) {
            return $prefix . $viewName;
        }

        return $viewName;
    }

    /**
     * Check if a specific role can access a column.
     * Override this in subclasses or via hook for dynamic column permissions.
     */
    private static function checkColumnAccess(string $table, string $column, string $role): bool
    {
        $data = [
            'table' => $table,
            'column' => $column,
            'role' => $role,
        ];

        if (function_exists('hook_invoke_all')) {
            hook_invoke_all('ksf_FA_RBAC', 'checkColumnAccess', $data);
            if (isset($data['allowed'])) {
                return $data['allowed'];
            }
        }

        return true;
    }
}
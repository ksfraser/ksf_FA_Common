<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Traits;

use ksfraser\FrontAccounting\Common\Utils\RbacGateway;

/**
 * RbacGatewayTrait - Provides RBAC authorization methods for hooks classes.
 *
 * Use this trait in your module's hooks class to get convenient RBAC methods:
 *
 *   class hooks_ksf_FA_Customer extends hooks {
 *       use RbacGatewayTrait;
 *   }
 *
 * Then in your code:
 *   if ($this->rbacCheck('view', 'customer', $customerId)) {
 *       // allow
 *   }
 *
 * View-based column filtering:
 *   $view = $this->rbacGetView('customer');
 *   $sql = "SELECT * FROM {$view} WHERE ...";
 *
 * @since 1.5.0
 */
trait RbacGatewayTrait
{
    /**
     * Check RBAC access for an action on a resource.
     *
     * @param string $action Action name (view, edit, delete, create, etc.)
     * @param string $module Module name
     * @param int|null $resourceId Optional record ID
     * @param mixed $resource Optional resource object
     * @return bool True if allowed, false if denied
     *
     * @since 1.5.0
     */
    protected function rbacCheck(string $action, string $module, ?int $resourceId = null, $resource = null): bool
    {
        $result = RbacGateway::checkAccess($action, $module, $resourceId, $resource);

        if ($result === RbacGateway::ALLOW) {
            return true;
        }

        if ($result === RbacGateway::DENY) {
            return false;
        }

        return false;
    }

    /**
     * Check if RBAC grants access (alias for rbacCheck that returns early on DENY).
     *
     * @param string $action
     * @param string $module
     * @param int|null $resourceId
     * @param mixed $resource
     * @return bool
     *
     * @since 1.5.0
     */
    protected function rbacAllow(string $action, string $module, ?int $resourceId = null, $resource = null): bool
    {
        return RbacGateway::checkAccess($action, $module, $resourceId, $resource) === RbacGateway::ALLOW;
    }

    /**
     * Filter a SQL query based on RBAC rules.
     *
     * @param string $module
     * @param string $sql
     * @param array $params
     * @param string $action
     * @return array [filteredSql, params, wasFiltered]
     *
     * @since 1.5.0
     */
    protected function rbacFilter(string $module, string $sql, array $params = [], string $action = 'list'): array
    {
        $originalSql = $sql;
        $originalParams = $params;

        $result = RbacGateway::filterRecordList($module, $sql, $params, $action);

        $wasFiltered = $result === RbacGateway::ALLOW && $sql !== $originalSql;

        return [$sql, $params, $wasFiltered];
    }

    /**
     * Get the MySQL view name for a table based on user's role.
     *
     * Use to route queries through column-filtered views:
     *   $view = $this->rbacGetView('customer');
     *   $sql = "SELECT * FROM {$view} WHERE ...";
     *
     * @param string $table Base table name
     * @param string $prefix Optional table prefix
     * @return string View name (or base table if no filtered view)
     *
     * @since 1.5.0
     */
    protected function rbacGetView(string $table, string $prefix = ''): string
    {
        return RbacGateway::getViewForTable($table, $prefix);
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
    protected function rbacCanAccessColumn(string $table, string $column): bool
    {
        return RbacGateway::canAccessColumn($table, $column);
    }

    /**
     * Filter SQL to only include permitted columns.
     *
     * @param string $sql
     * @param string $table
     * @param array $allowedColumns
     * @return string
     *
     * @since 1.5.0
     */
    protected function rbacFilterColumns(string $sql, string $table, array $allowedColumns): string
    {
        return RbacGateway::filterColumns($sql, $table, $allowedColumns);
    }

    /**
     * Check if current user has a specific role.
     *
     * @param string $role
     * @return bool
     *
     * @since 1.5.0
     */
    protected function rbacHasRole(string $role): bool
    {
        return RbacGateway::hasRole($role);
    }

    /**
     * Get current user ID.
     *
     * @return int
     *
     * @since 1.5.0
     */
    protected function rbacGetUserId(): int
    {
        return RbacGateway::getUserId();
    }

    /**
     * Check if RBAC module is available.
     *
     * @return bool
     *
     * @since 1.5.0
     */
    protected function rbacIsAvailable(): bool
    {
        return RbacGateway::isAvailable();
    }

    /**
     * Get the recipient chain for encryption.
     *
     * Returns all recipients (user, team, managers, IT backup) for
     * multi-recipient encryption.
     *
     * @param int $userId
     * @return array
     *
     * @since 1.5.0
     */
    protected function rbacGetEncryptionRecipients(int $userId): array
    {
        if (!class_exists('ksfraser\\FrontAccounting\\Common\\Utils\\MultiRecipientEncryption')) {
            return [$userId];
        }

        global $db_connections;
        $prefix = $db_connections[$_SESSION['wa_current_user']->company]['tbpref'];

        $mre = new \ksfraser\FrontAccounting\Common\Utils\MultiRecipientEncryption(
            $this->getDbAdapter(),
            $prefix
        );

        return $mre->getRecipientChain($userId);
    }

    /**
     * Get a DB adapter for direct queries.
     *
     * @return \ksfraser\CommonDb\Adapter\FaDbAdapter
     *
     * @since 1.5.0
     */
    protected function getDbAdapter()
    {
        if (!class_exists('ksfraser\\CommonDb\\Adapter\\FaDbAdapter')) {
            throw new \RuntimeException('ksf-common-db not available');
        }

        global $db_connections;
        $prefix = $db_connections[$_SESSION['wa_current_user']->company]['tbpref'];

        return new \ksfraser\CommonDb\Adapter\FaDbAdapter($prefix);
    }
}
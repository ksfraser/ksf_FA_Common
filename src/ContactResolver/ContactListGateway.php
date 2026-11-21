<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ContactResolver;

/**
 * SRP gateway for native FA person table lookups.
 *
 * Encapsulates all raw SQL against FA core's 0_crm_persons table used by
 * contact option lists and relate-to lookups. Query construction is split
 * from execution: `sql*()` methods return SQL strings, `*()` methods execute
 * them via FA's native `db_*` calls.
 *
 * This follows the same discipline as `ContactsRepository` and
 * `NotesRepository`: SQL building and SQL execution live in separate methods
 * so the SQL can be asserted in unit tests without a database.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver
 * @since   2.3.0
 */
final class ContactListGateway
{
    /**
     * Build SELECT id, name, name2, email FROM 0_crm_persons.
     *
     * Optionally filters to a list of person ids via WHERE id IN (...).
     *
     * @param bool   $activeOnly Skip inactive persons when true
     * @param int[]  $ids        Optional list of person ids to filter by
     * @return string SQL statement
     * @since  2.3.0
     */
    public function sqlListContacts(bool $activeOnly = true, array $ids = []): string
    {
        $sql = 'SELECT id, name, name2, email FROM ' . TB_PREF . 'crm_persons';

        $where = array();
        if ($activeOnly) {
            $where[] = 'inactive = 0';
        }
        if (!empty($ids)) {
            $idList = implode(',', array_map(fn ($id) => (int) $id, $ids));
            $where[] = 'id IN (' . $idList . ')';
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY name';

        return $sql;
    }

    /**
     * Execute sqlListContacts() and return all person rows.
     *
     * @param bool $activeOnly Skip inactive persons when true
     * @return array<int, array> Person rows with id, name, name2, email keys
     * @since  2.3.0
     */
    public function listContacts(bool $activeOnly = true): array
    {
        return $this->listContactsByIds($activeOnly, array());
    }

    /**
     * Execute sqlListContacts() and return person rows filtered by ids.
     *
     * @param bool $activeOnly Skip inactive persons when true
     * @param int[]  $ids      List of person ids to fetch
     * @return array<int, array> Person rows with id, name, name2, email keys
     * @since  2.3.0
     */
    public function listContactsByIds(bool $activeOnly = true, array $ids = []): array
    {
        if (!function_exists('db_query') || !defined('TB_PREF') || !function_exists('db_fetch_assoc')) {
            return array();
        }

        $result = db_query($this->sqlListContacts($activeOnly, $ids));
        if ($result === false) {
            return array();
        }

        $rows = array();
        while (($row = db_fetch_assoc($result)) !== false && $row !== null) {
            $rows[] = $row;
        }

        return $rows;
    }
}

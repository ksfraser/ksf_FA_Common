<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Matching;

/**
 * SqlPreFilter — SQL-side candidate pre-filtering using native FA db_* calls.
 *
 * Dual-path execution:
 *   * If the target table is `gl_trans` and FA core's `get_gl_transactions()`
 *     is available, it delegates to that function (FA-native, handles date
 *     ranges, amount min/max, GL account, dimensions, transaction type,
 *     person_id).
 *   * For any other table (ISP staging tables, Bank Import bi_transactions,
 *     etc.) it builds and executes raw SQL via db_query/db_escape, with all
 *     user values escaped to prevent SQL injection.
 *
 * This keeps FA runtime free of PDO: all SQL goes through db_query/db_escape/
 * db_fetch_assoc. The table prefix convention uses literal '0_' (not
 * {TB_PREF}), per AGENTS.md §FA extension install mechanics.
 *
 * @since 3.0.0
 * @BABOK Related: FR-COM-MATCH-017 SQL pre-filtering via db_*
 * @BABOK Related: NFR-COM-MATCH-003 native db_* calls only
 * @BABOK Related: NFR-COM-MATCH-004 SQL injection prevention via db_escape
 */
class SqlPreFilter
{
    /** @var string Table name (with '0_' prefix if applicable) */
    private $table;

    /** @var string Date column name */
    private $dateColumn;

    /** @var string Amount column name */
    private $amountColumn;

    /** @var string Optional SQL fragment appended to FROM (e.g. joins) */
    private $fromFragment = '';

    /** @var string Optional SQL fragment for additional WHERE conditions */
    private $whereFragment = '';

    /**
     * @param string $table        Table name
     * @param string $dateColumn   Date column name
     * @param string $amountColumn Amount column name
     * @param string $fromFragment Optional FROM fragment
     * @param string $whereFragment Optional WHERE fragment
     * @since 3.0.0
     */
    public function __construct(
        $table,
        $dateColumn,
        $amountColumn,
        $fromFragment = '',
        $whereFragment = ''
    ) {
        $this->table        = (string)$table;
        $this->dateColumn   = (string)$dateColumn;
        $this->amountColumn = (string)$amountColumn;
        $this->fromFragment = (string)$fromFragment;
        $this->whereFragment = (string)$whereFragment;
    }

    /**
     * Execute a pre-filter query and return candidate rows as associative arrays.
     *
     * @param SearchCriteria $criteria Search criteria
     * @param int            $limit    Maximum rows to return
     * @return array Associative row arrays
     * @since 3.0.0
     */
    public function filter(SearchCriteria $criteria, $limit = 100)
    {
        // Use FA core's get_gl_transactions() for gl_trans queries when
        // available — it's the FA-native way and already handles date
        // ranges, amount min/max, GL account, dimensions, transaction
        // type, and person_id.
        if ($this->table === 'gl_trans' && function_exists('get_gl_transactions')) {
            return $this->filterViaGetGlTransactions($criteria);
        }

        // Raw SQL path for staging tables and other non-gl_trans tables.
        $sql = $this->buildSelectSql($criteria, $limit);
        $result = db_query($sql, 'Candidate pre-filter query failed');
        $rows = array();
        while ($row = db_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Filter gl_trans via FA core's get_gl_transactions().
     *
     * Maps SearchCriteria fields to get_gl_transactions() parameters:
     *   date_from     -> $from_date
     *   date_to       -> $to_date
     *   gl_account    -> $account
     *   transaction_type -> $filter_type
     *   amount_min    -> $amount_min
     *   amount_max    -> $amount_max
     *   person_id     -> $person_id (filters gl.person_id = 0_crm_persons.id)
     *
     * Note: get_gl_transactions() only accepts a single person_id for exact
     * matching. Customer/supplier name fragments, email, name, and phone
     * filters are NOT supported by get_gl_transactions() and are applied
     * PHP-side by GenericMatcher after candidate retrieval.
     *
     * @param SearchCriteria $criteria
     * @return array Associative row arrays
     * @since 3.0.0
     */
    private function filterViaGetGlTransactions(SearchCriteria $criteria)
    {
        $fromDate = $criteria->getDateFrom() ?: '';
        $toDate   = $criteria->getDateTo() ?: '';

        // get_gl_transactions requires non-empty from/to dates; default to
        // a wide range when not specified.
        if ($fromDate === '') {
            $fromDate = '1970-01-01';
        }
        if ($toDate === '') {
            $toDate = '2099-12-31';
        }

        $result = get_gl_transactions(
            $fromDate,
            $toDate,
            0,                                            // trans_no (unused)
            $criteria->getGlAccount() !== null ? $criteria->getGlAccount() : null,
            0,                                            // dimension (unused)
            0,                                            // dimension2 (unused)
            $criteria->getTransactionType() !== null ? $criteria->getTransactionType() : null,
            $criteria->getAmountMin() !== null ? $criteria->getAmountMin() : null,
            $criteria->getAmountMax() !== null ? $criteria->getAmountMax() : null,
            $criteria->getPersonId()                      // person_id: exact match on gl.person_id
        );

        $rows = array();
        while ($row = db_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Build the full SELECT SQL with WHERE clause from SearchCriteria.
     *
     * @param SearchCriteria $criteria
     * @param int            $limit
     * @return string SQL string
     * @since 3.0.0
     */
    public function buildSelectSql(SearchCriteria $criteria, $limit = 100)
    {
        $where = $this->buildWhereClause($criteria);
        $whereSql = $where === '' ? '' : ' WHERE ' . $where;

        $sql = sprintf(
            'SELECT * FROM %s%s%s ORDER BY %s DESC LIMIT %d',
            $this->table,
            $this->fromFragment !== '' ? ' ' . $this->fromFragment : '',
            $whereSql,
            $this->dateColumn,
            (int)$limit
        );

        return $sql;
    }

    /**
     * Build the WHERE clause from SearchCriteria.
     *
     * All user-supplied values are escaped via db_escape to prevent SQL
     * injection. Numeric values are cast to their expected types.
     *
     * @param SearchCriteria $criteria
     * @return string WHERE clause without the 'WHERE' keyword
     * @since 3.0.0
     */
    public function buildWhereClause(SearchCriteria $criteria)
    {
        $conditions = array();

        // Date range
        if ($criteria->getDateFrom() !== null) {
            $conditions[] = sprintf(
                '%s >= %s',
                $this->dateColumn,
                db_escape($criteria->getDateFrom())
            );
        }
        if ($criteria->getDateTo() !== null) {
            $conditions[] = sprintf(
                '%s <= %s',
                $this->dateColumn,
                db_escape($criteria->getDateTo())
            );
        }

        // Amount range (absolute value)
        if ($criteria->getAmountMin() !== null) {
            $conditions[] = sprintf(
                'ABS(%s) >= %s',
                $this->amountColumn,
                (float)$criteria->getAmountMin()
            );
        }
        if ($criteria->getAmountMax() !== null) {
            $conditions[] = sprintf(
                'ABS(%s) <= %s',
                $this->amountColumn,
                (float)$criteria->getAmountMax()
            );
        }

        // GL account
        if ($criteria->getGlAccount() !== null) {
            $conditions[] = sprintf(
                'account = %d',
                (int)$criteria->getGlAccount()
            );
        }

        // Person id (exact match on 0_crm_persons.id)
        if ($criteria->getPersonId() !== null) {
            $conditions[] = sprintf(
                'person_id = %d',
                (int)$criteria->getPersonId()
            );
        }

        // Customer name fragment (LIKE)
        if ($criteria->getCustomer() !== null) {
            $conditions[] = sprintf(
                'name LIKE %s',
                db_escape('%' . $criteria->getCustomer() . '%')
            );
        }

        // Supplier name fragment (LIKE)
        if ($criteria->getSupplier() !== null) {
            $conditions[] = sprintf(
                'supplier_name LIKE %s',
                db_escape('%' . $criteria->getSupplier() . '%')
            );
        }

        // Email fragment (LIKE)
        if ($criteria->getEmail() !== null) {
            $conditions[] = sprintf(
                'email LIKE %s',
                db_escape('%' . $criteria->getEmail() . '%')
            );
        }

        // Name fragment (LIKE)
        if ($criteria->getName() !== null) {
            $conditions[] = sprintf(
                'name LIKE %s',
                db_escape('%' . $criteria->getName() . '%')
            );
        }

        // Phone fragment (LIKE)
        if ($criteria->getPhone() !== null) {
            $conditions[] = sprintf(
                'phone LIKE %s',
                db_escape('%' . $criteria->getPhone() . '%')
            );
        }

        // Reference fragment (LIKE)
        if ($criteria->getReference() !== null) {
            $conditions[] = sprintf(
                'reference LIKE %s',
                db_escape('%' . $criteria->getReference() . '%')
            );
        }

        // Transaction type
        if ($criteria->getTransactionType() !== null) {
            $conditions[] = sprintf(
                'type = %d',
                (int)$criteria->getTransactionType()
            );
        }

        // Custom WHERE fragment (caller is responsible for safety)
        if ($criteria->getCustomWhere() !== null && $criteria->getCustomWhere() !== '') {
            $conditions[] = $criteria->getCustomWhere();
        }

        // Caller-supplied fragment
        if ($this->whereFragment !== '') {
            $conditions[] = $this->whereFragment;
        }

        return implode(' AND ', $conditions);
    }

    /** @return string */
    public function getTable()
    {
        return $this->table;
    }

    /** @return string */
    public function getDateColumn()
    {
        return $this->dateColumn;
    }

    /** @return string */
    public function getAmountColumn()
    {
        return $this->amountColumn;
    }
}

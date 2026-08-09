<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ItemEvents;

/**
 * FA Item Snapshot Source
 *
 * DB-backed ItemSnapshotSourceInterface that fingerprints every FA stock
 * item by reading stock_master plus its item_codes (aliases, foreign SKUs).
 *
 * A fingerprint is md5(serialize(item)) of the fields relevant to commerce
 * sync: description, units, MB flag, category, tax type, costs, accounts,
 * active flags and the full item_codes set. Any FA-native edit to these
 * fields changes the fingerprint so the watcher can emit item_updated.
 *
 * When the FA DB helpers are unavailable (unit tests) fetchAll() returns an
 * empty set so callers degrade gracefully.
 *
 * @package KsfCommon
 * @since   1.6.0
 */
class FASnapshotSource implements ItemSnapshotSourceInterface
{
    /**
     * @return array<string, array{fingerprint: string, raw: array}> Keyed by stock_id
     *
     * @since 1.6.0
     */
    public function fetchAll(): array
    {
        if (!$this->dbAvailable()) {
            return [];
        }

        $items = $this->fetchStockMaster();
        $codes = $this->fetchItemCodes();

        $snapshots = [];
        foreach ($items as $stockId => $row) {
            $row['item_codes'] = isset($codes[$stockId]) ? $codes[$stockId] : [];
            $snapshots[$stockId] = [
                'fingerprint' => md5(serialize($row)),
                'raw'         => $row,
            ];
        }

        return $snapshots;
    }

    /**
     * Load the commerce-relevant columns of every stock item.
     *
     * @return array<string, array> Keyed by stock_id
     *
     * @since 1.6.0
     */
    private function fetchStockMaster(): array
    {
        $sql = sprintf(
            'SELECT stock_id, category_id, tax_type_id, description, long_description,'
            . ' units, mb_flag, sales_account, cogs_account, inventory_account,'
            . ' purchase_cost, material_cost, labour_cost, overhead_cost,'
            . ' inactive, no_sale, no_purchase, editable'
            . ' FROM %s',
            $this->table('stock_master')
        );
        $result = db_query($sql, 'Failed to load stock items for sync scan');

        $rows = [];
        while ($result && ($row = db_fetch_assoc($result))) {
            $rows[(string) $row['stock_id']] = $row;
        }
        return $rows;
    }

    /**
     * Load the item_codes (aliases / foreign codes) grouped per stock item.
     *
     * @return array<string, array<int, array>> Keyed by stock_id
     *
     * @since 1.6.0
     */
    private function fetchItemCodes(): array
    {
        $sql = sprintf(
            'SELECT stock_id, item_code, description, quantity, is_foreign, inactive'
            . ' FROM %s ORDER BY stock_id, id',
            $this->table('item_codes')
        );
        $result = db_query($sql, 'Failed to load item codes for sync scan');

        $codes = [];
        while ($result && ($row = db_fetch_assoc($result))) {
            $codes[(string) $row['stock_id']][] = $row;
        }
        return $codes;
    }

    /**
     * Resolve the full table name with the FA company prefix.
     *
     * @param string $table Unprefixed table name
     *
     * @return string
     *
     * @since 1.6.0
     */
    private function table(string $table): string
    {
        return defined('TB_PREF') ? TB_PREF . $table : $table;
    }

    /**
     * Whether FA DB helpers are loaded.
     *
     * @return bool
     *
     * @since 1.6.0
     */
    private function dbAvailable(): bool
    {
        return function_exists('db_query') && function_exists('db_fetch_assoc');
    }
}

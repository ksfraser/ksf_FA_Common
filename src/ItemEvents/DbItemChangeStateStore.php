<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ItemEvents;

/**
 * DB Item Change State Store
 *
 * DB-backed implementation of ItemChangeStateStoreInterface that persists
 * item fingerprints and the scan watermark in the FA company database:
 *
 *   {TB_PREF}ksf_item_sync_state        per-item fingerprint + first/last seen
 *   {TB_PREF}ksf_item_event_watermark   single-row scan watermark
 *
 * When the FA DB helpers are unavailable (e.g. unit tests, CLI tools outside
 * FA) the store transparently falls back to in-memory state so callers never
 * break.
 *
 * @package KsfCommon
 * @since   1.6.0
 */
class DbItemChangeStateStore implements ItemChangeStateStoreInterface
{
    /** @var string Unprefixed sync-state table name. */
    const TABLE_STATE = 'ksf_item_sync_state';

    /** @var string Unprefixed watermark table name. */
    const TABLE_WATERMARK = 'ksf_item_event_watermark';

    /** @var array<string, array{fingerprint: string}> In-memory fallback state. */
    private $memory = [];

    /** @var string|null In-memory fallback watermark. */
    private $memoryWatermark = null;

    /** @var bool Whether the sync-state tables have been ensured this request. */
    private $schemaEnsured = false;

    /**
     * Ensure the sync-state tables exist (created on demand).
     *
     * The package owns the schema; there is no module activation step that
     * installs shared tables any more.
     */
    private function ensureTable(): void
    {
        if ($this->schemaEnsured || !$this->dbAvailable()) {
            return;
        }

        $pref = defined('TB_PREF') ? TB_PREF : '';
        db_query(
            "CREATE TABLE IF NOT EXISTS `{$pref}" . self::TABLE_STATE . "` (\n"
            . "    `stock_id`      VARCHAR(20) NOT NULL COMMENT 'FA stock_id (SKU)',\n"
            . "    `fingerprint`   CHAR(32)    NOT NULL COMMENT 'md5 hash of the last seen item snapshot',\n"
            . "    `first_seen_at` DATETIME    NOT NULL COMMENT 'When the item was first tracked',\n"
            . "    `last_seen_at`  DATETIME    NOT NULL COMMENT 'When the item was last scanned',\n"
            . "    PRIMARY KEY (`stock_id`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8",
            'Could not create item sync state table: ' . self::TABLE_STATE
        );
        db_query(
            "CREATE TABLE IF NOT EXISTS `{$pref}" . self::TABLE_WATERMARK . "` (\n"
            . "    `id`        TINYINT(1) NOT NULL DEFAULT 1,\n"
            . "    `watermark` DATETIME   NOT NULL COMMENT 'Timestamp of the most recent watcher scan',\n"
            . "    PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8",
            'Could not create item event watermark table: ' . self::TABLE_WATERMARK
        );

        $this->schemaEnsured = true;
    }

    /**
     * @param string $stockId FA stock_id
     *
     * @return bool
     *
     * @since 1.6.0
     */
    public function has(string $stockId): bool
    {
        return $this->get($stockId) !== null;
    }

    /**
     * @param string $stockId FA stock_id
     *
     * @return array{fingerprint: string}|null
     *
     * @since 1.6.0
     */
    public function get(string $stockId): ?array
    {
        $this->ensureTable();

        if (!$this->dbAvailable()) {
            return $this->memory[$stockId] ?? null;
        }

        $sql = sprintf(
            'SELECT fingerprint FROM %s WHERE stock_id = %s LIMIT 1',
            $this->table(self::TABLE_STATE),
            db_escape($stockId)
        );
        $result = db_query($sql, 'Failed to load item sync state');
        $row = db_fetch_assoc($result);

        return $row ? ['fingerprint' => (string) $row['fingerprint']] : null;
    }

    /**
     * @param string $stockId FA stock_id
     * @param array  $state   State array (must contain a 'fingerprint' key)
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function set(string $stockId, array $state): void
    {
        $fingerprint = isset($state['fingerprint']) ? (string) $state['fingerprint'] : '';

        $this->ensureTable();

        if (!$this->dbAvailable()) {
            $this->memory[$stockId] = ['fingerprint' => $fingerprint];
            return;
        }

        $sql = sprintf(
            'INSERT INTO %s (stock_id, fingerprint, first_seen_at, last_seen_at)'
            . ' VALUES (%s, %s, NOW(), NOW())'
            . ' ON DUPLICATE KEY UPDATE fingerprint = %s, last_seen_at = NOW()',
            $this->table(self::TABLE_STATE),
            db_escape($stockId),
            db_escape($fingerprint),
            db_escape($fingerprint)
        );
        db_query($sql, 'Failed to save item sync state');
    }

    /**
     * @param string $stockId FA stock_id
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function remove(string $stockId): void
    {
        $this->ensureTable();

        if (!$this->dbAvailable()) {
            unset($this->memory[$stockId]);
            return;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE stock_id = %s',
            $this->table(self::TABLE_STATE),
            db_escape($stockId)
        );
        db_query($sql, 'Failed to remove item sync state');
    }

    /**
     * @return array<int, string>
     *
     * @since 1.6.0
     */
    public function allStockIds(): array
    {
        $this->ensureTable();

        if (!$this->dbAvailable()) {
            return array_keys($this->memory);
        }

        $sql = sprintf('SELECT stock_id FROM %s', $this->table(self::TABLE_STATE));
        $result = db_query($sql, 'Failed to list item sync state');

        $ids = [];
        while ($result && ($row = db_fetch_assoc($result))) {
            $ids[] = (string) $row['stock_id'];
        }
        return $ids;
    }

    /**
     * @return string|null 'Y-m-d H:i:s'
     *
     * @since 1.6.0
     */
    public function getWatermark(): ?string
    {
        $this->ensureTable();

        if (!$this->dbAvailable()) {
            return $this->memoryWatermark;
        }

        $sql = sprintf('SELECT watermark FROM %s WHERE id = 1', $this->table(self::TABLE_WATERMARK));
        $result = db_query($sql, 'Failed to load event watermark');
        $row = db_fetch_assoc($result);

        return $row ? (string) $row['watermark'] : null;
    }

    /**
     * @param string $timestamp 'Y-m-d H:i:s'
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function setWatermark(string $timestamp): void
    {
        $this->ensureTable();

        if (!$this->dbAvailable()) {
            $this->memoryWatermark = $timestamp;
            return;
        }

        $sql = sprintf(
            'INSERT INTO %s (id, watermark) VALUES (1, %s)'
            . ' ON DUPLICATE KEY UPDATE watermark = %s',
            $this->table(self::TABLE_WATERMARK),
            db_escape($timestamp),
            db_escape($timestamp)
        );
        db_query($sql, 'Failed to save event watermark');
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

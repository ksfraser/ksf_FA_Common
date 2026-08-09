<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ItemEvents;

/**
 * Item Change State Store
 *
 * Persists per-item fingerprints (last seen snapshot) and the watermark of
 * the most recent scan. Implementations may be DB-backed (FA company DB) or
 * in-memory (unit tests, no FA available).
 *
 * @package KsfCommon
 * @since   1.6.0
 */
interface ItemChangeStateStoreInterface
{
    /**
     * Whether the store already tracks the given stock item.
     *
     * @param string $stockId FA stock_id
     *
     * @return bool
     *
     * @since 1.6.0
     */
    public function has(string $stockId): bool;

    /**
     * Get the stored state for an item.
     *
     * @param string $stockId FA stock_id
     *
     * @return array{fingerprint: string}|null
     *
     * @since 1.6.0
     */
    public function get(string $stockId): ?array;

    /**
     * Persist the current fingerprint for an item.
     *
     * @param string $stockId FA stock_id
     * @param array  $state   State array (must contain a 'fingerprint' key)
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function set(string $stockId, array $state): void;

    /**
     * Drop tracking for an item (e.g. item removed from stock_master).
     *
     * @param string $stockId FA stock_id
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function remove(string $stockId): void;

    /**
     * List all tracked stock_ids.
     *
     * @return array<int, string>
     *
     * @since 1.6.0
     */
    public function allStockIds(): array;

    /**
     * Timestamp of the most recent scan, or null when never scanned.
     *
     * @return string|null 'Y-m-d H:i:s'
     *
     * @since 1.6.0
     */
    public function getWatermark(): ?string;

    /**
     * Record the timestamp of the most recent scan.
     *
     * @param string $timestamp 'Y-m-d H:i:s'
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function setWatermark(string $timestamp): void;
}

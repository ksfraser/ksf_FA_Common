<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ItemEvents;

/**
 * Item Snapshot Source
 *
 * Produces a point-in-time fingerprint of every tracked FA stock item so
 * the ItemChangeWatcher can detect creates and updates between scans.
 *
 * @package KsfCommon
 * @since   1.6.0
 */
interface ItemSnapshotSourceInterface
{
    /**
     * Fetch the current state of every stock item.
     *
     * @return array<string, array{fingerprint: string, raw: array}> Keyed by stock_id
     *
     * @since 1.6.0
     */
    public function fetchAll(): array;
}

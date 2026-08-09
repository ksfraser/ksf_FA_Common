<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\ItemEvents;

use ksfraser\FrontAccounting\Common\ItemEvents\DbItemChangeStateStore;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the in-memory fallback path (no FA DB helpers loaded).
 * The DB-backed path is covered by integration tests against a real company DB.
 *
 * @BABOK Related: BR-Common-001-item-sync-events
 */
class DbItemChangeStateStoreTest extends TestCase
{
    public function testGetReturnsNullForUnknownItem(): void
    {
        $store = new DbItemChangeStateStore();

        $this->assertNull($store->get('SKU-UNKNOWN'));
    }

    public function testHasReturnsFalseForUnknownItem(): void
    {
        $store = new DbItemChangeStateStore();

        $this->assertFalse($store->has('SKU-UNKNOWN'));
    }

    public function testSetThenGetRoundTrip(): void
    {
        $store = new DbItemChangeStateStore();

        $store->set('SKU-001', ['fingerprint' => 'abc123']);

        $this->assertSame(['fingerprint' => 'abc123'], $store->get('SKU-001'));
        $this->assertTrue($store->has('SKU-001'));
    }

    public function testSetOverwritesExistingFingerprint(): void
    {
        $store = new DbItemChangeStateStore();
        $store->set('SKU-001', ['fingerprint' => 'old']);
        $store->set('SKU-001', ['fingerprint' => 'new']);

        $this->assertSame(['fingerprint' => 'new'], $store->get('SKU-001'));
    }

    public function testRemoveDeletesState(): void
    {
        $store = new DbItemChangeStateStore();
        $store->set('SKU-001', ['fingerprint' => 'abc']);
        $store->remove('SKU-001');

        $this->assertNull($store->get('SKU-001'));
        $this->assertFalse($store->has('SKU-001'));
    }

    public function testAllStockIdsListsTrackedItems(): void
    {
        $store = new DbItemChangeStateStore();
        $store->set('SKU-A', ['fingerprint' => '1']);
        $store->set('SKU-B', ['fingerprint' => '2']);

        $ids = $store->allStockIds();

        sort($ids);
        $this->assertSame(['SKU-A', 'SKU-B'], $ids);
    }

    public function testAllStockIdsEmptyWhenNothingTracked(): void
    {
        $store = new DbItemChangeStateStore();

        $this->assertSame([], $store->allStockIds());
    }

    public function testWatermarkRoundTrip(): void
    {
        $store = new DbItemChangeStateStore();

        $this->assertNull($store->getWatermark());

        $store->setWatermark('2026-07-31 12:00:00');

        $this->assertSame('2026-07-31 12:00:00', $store->getWatermark());
    }

    public function testWatermarkOverwrites(): void
    {
        $store = new DbItemChangeStateStore();
        $store->setWatermark('2026-07-31 12:00:00');
        $store->setWatermark('2026-07-31 13:00:00');

        $this->assertSame('2026-07-31 13:00:00', $store->getWatermark());
    }
}

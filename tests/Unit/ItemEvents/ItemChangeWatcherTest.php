<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\ItemEvents;

use ksfraser\FrontAccounting\Common\ItemEvents\ItemChangeStateStoreInterface;
use ksfraser\FrontAccounting\Common\ItemEvents\ItemChangeWatcher;
use ksfraser\FrontAccounting\Common\ItemEvents\ItemEventPublisher;
use ksfraser\FrontAccounting\Common\ItemEvents\ItemSnapshotSourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * @BABOK Related: BR-Common-001-item-sync-events
 */
class ItemChangeWatcherTest extends TestCase
{
    /** @var array<string, array{fingerprint: string}> */
    private $state = [];

    /** @var string|null */
    private $watermark = null;

    /** @var array<int, array{string, array}> */
    private $dispatched = [];

    /** @var array<string, array> */
    private $sourceData = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = [];
        $this->watermark = null;
        $this->dispatched = [];
        $this->sourceData = [];
    }

    private function buildWatcher(): ItemChangeWatcher
    {
        $source = new class($this->sourceData) implements ItemSnapshotSourceInterface {
            /** @var array<string, array> */
            private $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            public function fetchAll(): array
            {
                return $this->data;
            }
        };

        $store = new class($this->state, $this->watermark) implements ItemChangeStateStoreInterface {
            /** @var array<string, array{fingerprint: string}> */
            private $state;

            /** @var string|null */
            private $watermark;

            public function __construct(array &$state, ?string &$watermark)
            {
                $this->state = &$state;
                $this->watermark = &$watermark;
            }

            public function has(string $stockId): bool
            {
                return isset($this->state[$stockId]);
            }

            public function get(string $stockId): ?array
            {
                return $this->state[$stockId] ?? null;
            }

            public function set(string $stockId, array $state): void
            {
                $this->state[$stockId] = $state;
            }

            public function remove(string $stockId): void
            {
                unset($this->state[$stockId]);
            }

            public function allStockIds(): array
            {
                return array_keys($this->state);
            }

            public function getWatermark(): ?string
            {
                return $this->watermark;
            }

            public function setWatermark(string $timestamp): void
            {
                $this->watermark = $timestamp;
            }
        };

        $publisher = new ItemEventPublisher(function (string $hook, array $payload): void {
            $this->dispatched[] = [$hook, $payload];
        });

        return new ItemChangeWatcher($source, $store, $publisher);
    }

    public function testScanPublishesCreatedForNewItems(): void
    {
        $this->sourceData = [
            'SKU-001' => ['fingerprint' => 'aaa', 'raw' => []],
        ];
        $watcher = $this->buildWatcher();

        $events = $watcher->scan();

        $this->assertSame([['stock_id' => 'SKU-001', 'event' => 'created']], $events);
        $this->assertSame('item_created', $this->dispatched[0][0]);
        $this->assertSame('SKU-001', $this->dispatched[0][1]['stock_id']);
        $this->assertSame('watcher', $this->dispatched[0][1]['trigger']);
    }

    public function testScanPublishesUpdatedForChangedItems(): void
    {
        $this->state = ['SKU-001' => ['fingerprint' => 'aaa']];
        $this->sourceData = [
            'SKU-001' => ['fingerprint' => 'bbb', 'raw' => ['description' => 'New']],
        ];
        $watcher = $this->buildWatcher();

        $events = $watcher->scan();

        $this->assertSame([['stock_id' => 'SKU-001', 'event' => 'updated']], $events);
        $this->assertSame('item_updated', $this->dispatched[0][0]);
    }

    public function testScanSkipsUnchangedItems(): void
    {
        $this->state = ['SKU-001' => ['fingerprint' => 'aaa']];
        $this->sourceData = [
            'SKU-001' => ['fingerprint' => 'aaa', 'raw' => []],
        ];
        $watcher = $this->buildWatcher();

        $events = $watcher->scan();

        $this->assertSame([], $events);
        $this->assertSame([], $this->dispatched);
    }

    public function testScanPersistsCurrentFingerprints(): void
    {
        $this->state = ['SKU-001' => ['fingerprint' => 'old']];
        $this->sourceData = [
            'SKU-001' => ['fingerprint' => 'new', 'raw' => []],
        ];
        $watcher = $this->buildWatcher();

        $watcher->scan();

        $this->assertSame(['fingerprint' => 'new'], $this->state['SKU-001']);
    }

    public function testScanPrunesItemsNoLongerPresent(): void
    {
        $this->state = [
            'SKU-KEPT'  => ['fingerprint' => 'aaa'],
            'SKU-GONE'  => ['fingerprint' => 'bbb'],
        ];
        $this->sourceData = [
            'SKU-KEPT' => ['fingerprint' => 'aaa', 'raw' => []],
        ];
        $watcher = $this->buildWatcher();

        $watcher->scan();

        $this->assertArrayHasKey('SKU-KEPT', $this->state);
        $this->assertArrayNotHasKey('SKU-GONE', $this->state);
    }

    public function testScanAdvancesWatermark(): void
    {
        $watcher = $this->buildWatcher();

        $watcher->scan();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $this->watermark);
    }

    public function testScanEmptySourceIsNoOp(): void
    {
        $watcher = $this->buildWatcher();

        $events = $watcher->scan('cli');

        $this->assertSame([], $events);
        $this->assertSame([], $this->dispatched);
        $this->assertNotNull($this->watermark);
    }

    public function testScanUsesProvidedTrigger(): void
    {
        $this->sourceData = [
            'SKU-002' => ['fingerprint' => 'aaa', 'raw' => []],
        ];
        $watcher = $this->buildWatcher();

        $watcher->scan('cron');

        $this->assertSame('cron', $this->dispatched[0][1]['trigger']);
    }

    public function testScanMultipleItemsDetectsEachIndependently(): void
    {
        $this->state = [
            'SKU-OLD' => ['fingerprint' => 'same'],
            'SKU-CHG' => ['fingerprint' => 'y1'],
        ];
        $this->sourceData = [
            'SKU-OLD' => ['fingerprint' => 'same', 'raw' => []],
            'SKU-NEW' => ['fingerprint' => 'x1', 'raw' => []],
            'SKU-CHG' => ['fingerprint' => 'y2', 'raw' => []],
        ];
        $watcher = $this->buildWatcher();

        $events = $watcher->scan();

        $this->assertSame([
            ['stock_id' => 'SKU-NEW', 'event' => 'created'],
            ['stock_id' => 'SKU-CHG', 'event' => 'updated'],
        ], $events);
    }
}

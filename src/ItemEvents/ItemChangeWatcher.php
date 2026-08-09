<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ItemEvents;

/**
 * Item Change Watcher
 *
 * Detects FA stock item creates and updates by comparing a fresh snapshot
 * (ItemSnapshotSourceInterface) against the last seen state
 * (ItemChangeStateStoreInterface) and broadcasts the differences through an
 * ItemEventPublisher.
 *
 *   - new stock_id       -> item_created
 *   - changed fingerprint -> item_updated
 *   - unchanged           -> skipped
 *   - no longer present   -> tracking pruned (item removed / deactivated)
 *
 * Every scan advances the store watermark to the current time.
 *
 * @package KsfCommon
 * @since   1.6.0
 */
class ItemChangeWatcher
{
    /** @var ItemSnapshotSourceInterface */
    private $source;

    /** @var ItemChangeStateStoreInterface */
    private $store;

    /** @var ItemEventPublisher */
    private $publisher;

    /**
     * @param ItemSnapshotSourceInterface   $source    Fresh item fingerprints
     * @param ItemChangeStateStoreInterface $store     Last seen state + watermark
     * @param ItemEventPublisher            $publisher Broadcasts created/updated events
     *
     * @since 1.6.0
     */
    public function __construct(
        ItemSnapshotSourceInterface $source,
        ItemChangeStateStoreInterface $store,
        ItemEventPublisher $publisher
    ) {
        $this->source = $source;
        $this->store = $store;
        $this->publisher = $publisher;
    }

    /**
     * Run one scan: detect changes, publish events, persist state + watermark.
     *
     * @param string $trigger Origin of the scan (defaults to 'watcher')
     *
     * @return array<int, array{stock_id: string, event: string}>
     *
     * @since 1.6.0
     */
    public function scan(string $trigger = 'watcher'): array
    {
        $events = [];
        $snapshots = $this->source->fetchAll();

        foreach ($snapshots as $stockId => $snapshot) {
            $stored = $this->store->get($stockId);

            if ($stored === null) {
                $this->publisher->publishCreated($stockId, ['fingerprint' => $snapshot['fingerprint']], $trigger);
                $events[] = ['stock_id' => $stockId, 'event' => ItemEventPublisher::EVENT_CREATED];
            } elseif ($stored['fingerprint'] !== $snapshot['fingerprint']) {
                $this->publisher->publishUpdated($stockId, ['fingerprint' => $snapshot['fingerprint']], $trigger);
                $events[] = ['stock_id' => $stockId, 'event' => ItemEventPublisher::EVENT_UPDATED];
            }

            $this->store->set($stockId, ['fingerprint' => $snapshot['fingerprint']]);
        }

        $this->pruneRemoved($snapshots);

        $this->store->setWatermark(date('Y-m-d H:i:s'));

        return $events;
    }

    /**
     * Forget items no longer present in the snapshot.
     *
     * @param array<string, array> $snapshots Current snapshot keyed by stock_id
     *
     * @return void
     *
     * @since 1.6.0
     */
    private function pruneRemoved(array $snapshots): void
    {
        foreach ($this->store->allStockIds() as $stockId) {
            if (!isset($snapshots[$stockId])) {
                $this->store->remove($stockId);
            }
        }
    }
}

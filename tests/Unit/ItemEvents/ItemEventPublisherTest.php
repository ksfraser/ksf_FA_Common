<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\ItemEvents;

use ksfraser\FrontAccounting\Common\ItemEvents\ItemEventPublisher;
use PHPUnit\Framework\TestCase;

/**
 * @BABOK Related: BR-Common-001-item-sync-events
 */
class ItemEventPublisherTest extends TestCase
{
    /** @var array<int, array{string, array}> */
    private $dispatched = [];

    /**
     * Build a publisher with a recording dispatcher.
     */
    private function publisherWithDispatcher(?callable $knownChecker = null): ItemEventPublisher
    {
        $this->dispatched = [];
        $recorder = function (string $hook, array $payload): void {
            $this->dispatched[] = [$hook, $payload];
        };

        return new ItemEventPublisher($recorder, $knownChecker);
    }

    public function testPublishCreatedDispatchesItemCreatedHook(): void
    {
        $publisher = $this->publisherWithDispatcher();

        $publisher->publishCreated('SKU-001');

        $this->assertCount(1, $this->dispatched);
        $this->assertSame('item_created', $this->dispatched[0][0]);
    }

    public function testPublishCreatedPayloadShape(): void
    {
        $publisher = $this->publisherWithDispatcher();

        $publisher->publishCreated('SKU-001');

        $payload = $this->dispatched[0][1];
        $this->assertSame('SKU-001', $payload['stock_id']);
        $this->assertSame('created', $payload['event']);
        $this->assertSame('publisher', $payload['trigger']);
        $this->assertIsString($payload['timestamp']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $payload['timestamp']);
        $this->assertSame([], $payload['data']);
    }

    public function testPublishUpdatedDispatchesItemUpdatedHook(): void
    {
        $publisher = $this->publisherWithDispatcher();

        $publisher->publishUpdated('SKU-002');

        $this->assertCount(1, $this->dispatched);
        $this->assertSame('item_updated', $this->dispatched[0][0]);
        $this->assertSame('updated', $this->dispatched[0][1]['event']);
    }

    public function testPublishCreatedPassesContextIntoDataKey(): void
    {
        $publisher = $this->publisherWithDispatcher();

        $publisher->publishCreated('SKU-003', ['name' => 'Widget', 'description' => 'A widget']);

        $this->assertSame(
            ['name' => 'Widget', 'description' => 'A widget'],
            $this->dispatched[0][1]['data']
        );
    }

    public function testPublishUsesProvidedTrigger(): void
    {
        $publisher = $this->publisherWithDispatcher();

        $publisher->publishUpdated('SKU-004', [], 'watcher');

        $this->assertSame('watcher', $this->dispatched[0][1]['trigger']);
    }

    public function testPublishAcceptsGenericEventName(): void
    {
        $publisher = $this->publisherWithDispatcher();

        $publisher->publish('created', 'SKU-005', [], 'cli');

        $this->assertSame('item_created', $this->dispatched[0][0]);
        $this->assertSame('cli', $this->dispatched[0][1]['trigger']);
    }

    public function testPublishChangedUnknownItemEmitsCreated(): void
    {
        $knownChecker = function (string $stockId): bool {
            return false;
        };
        $publisher = $this->publisherWithDispatcher($knownChecker);

        $result = $publisher->publishChanged('SKU-006');

        $this->assertSame('created', $result);
        $this->assertSame('item_created', $this->dispatched[0][0]);
    }

    public function testPublishChangedKnownItemEmitsUpdated(): void
    {
        $knownChecker = function (string $stockId): bool {
            return true;
        };
        $publisher = $this->publisherWithDispatcher($knownChecker);

        $result = $publisher->publishChanged('SKU-007');

        $this->assertSame('updated', $result);
        $this->assertSame('item_updated', $this->dispatched[0][0]);
    }

    public function testPublishChangedPassesStockIdToKnownChecker(): void
    {
        $seen = [];
        $knownChecker = function (string $stockId) use (&$seen): bool {
            $seen[] = $stockId;
            return false;
        };
        $publisher = $this->publisherWithDispatcher($knownChecker);

        $publisher->publishChanged('SKU-008');

        $this->assertSame(['SKU-008'], $seen);
    }

    public function testPublishChangedWithoutKnownCheckerDefaultsToCreated(): void
    {
        $publisher = $this->publisherWithDispatcher();

        $result = $publisher->publishChanged('SKU-009');

        $this->assertSame('created', $result);
        $this->assertSame('item_created', $this->dispatched[0][0]);
    }

    public function testPublishWithoutDispatcherIsNoOp(): void
    {
        $publisher = new ItemEventPublisher();

        $publisher->publishCreated('SKU-010');

        // No FA hooks loaded in the test environment: no exception, no output.
        $this->assertTrue(true);
    }

    public function testPublishChangedWithoutDispatcherAndCheckerDefaultsToCreated(): void
    {
        $publisher = new ItemEventPublisher();

        $result = $publisher->publishChanged('SKU-011');

        $this->assertSame('created', $result);
    }
}

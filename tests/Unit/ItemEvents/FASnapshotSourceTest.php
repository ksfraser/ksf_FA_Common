<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\ItemEvents;

use ksfraser\FrontAccounting\Common\ItemEvents\FASnapshotSource;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the degraded path when FA DB helpers are unavailable.
 * The DB-backed path is covered by integration tests against a real company DB.
 *
 * @BABOK Related: BR-Common-001-item-sync-events
 */
class FASnapshotSourceTest extends TestCase
{
    public function testFetchAllReturnsEmptyWhenNoDbFunctions(): void
    {
        $source = new FASnapshotSource();

        $this->assertSame([], $source->fetchAll());
    }
}

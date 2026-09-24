<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\Common\Scheduler\SystemClock;

final class SystemClockTest extends TestCase
{
    public function testNowReturnsImmutableWallClock(): void
    {
        $before = new DateTimeImmutable('now');

        $clock = new SystemClock();
        $now   = $clock->now();

        $this->assertInstanceOf(DateTimeImmutable::class, $now);
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
    }
}
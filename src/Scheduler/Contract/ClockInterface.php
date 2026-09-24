<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Scheduler\Contract;

use DateTimeImmutable;

/**
 * Wall-clock provider (BR-COM-04 FR-COM-04-003 FR-COM-04-005).
 *
 * Injectable because every due-check/backoff/run timestamp must be testable:
 * FA runtime injects a real clock; unit tests inject a FakeClock. Nothing in
 * the scheduler may call `time()`/`date()` directly.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
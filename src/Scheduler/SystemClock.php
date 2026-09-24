<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Scheduler;

use DateTimeImmutable;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\ClockInterface;

/**
 * Wall-clock provider for FA runtime (BR-COM-04 FR-COM-04-005).
 *
 * Standalone counterpart to the injected fake clocks used in tests: the
 * scheduler never calls time()/date()/NOW() directly — it asks this clock,
 * exactly like the SpiesClock/JobRunnerGuardClock fakes. Tests inject the
 * fakes; the FA adapter path injects this.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
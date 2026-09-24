<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Scheduler;

use RuntimeException;

/**
 * Raised by a resolver when its scheduled work is no longer applicable
 * (idempotent no-op) — e.g. the time-wait wire finds the instance has already
 * left the `from` state (BR-COM-04 FR-COM-04-007). The JobRunner logs a
 * 'skipped' run row and completes the job without backoff or a failure count.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class JobSkippedException extends RuntimeException
{
}
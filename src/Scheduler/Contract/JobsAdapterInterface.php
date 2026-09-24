<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Scheduler\Contract;

use DateTimeImmutable;

/**
 * Scheduler registry access (BR-COM-04 FR-COM-04-001).
 *
 * Implemented by FaJobsAdapter in FA runtime and InMemoryJobsAdapter for unit
 * tests/standalone. The adapter owns the job registry rows + append-only
 * run_log; the JobRunner loop owns the due/lock/backoff semantics.
 *
 * All timestamps follow the injected clock (SystemClock inside FA; a fake
 * clock in tests), so time passes explicitly — never NOW().
 *
 * @package KSF\Common
 * @since   2.0.0
 */
interface JobsAdapterInterface
{
    /**
     * Job rows due now: enabled AND next_run_at IS NOT NULL AND next_run_at <= now.
     *
     * @param DateTimeImmutable $now
     *
     * @return array<int,array<string,mixed>> job rows (schema columns)
     */
    public function listDue(DateTimeImmutable $now): array;

    /**
     * @param int $jobId
     *
     * @return array<string,mixed>|null job row or null when unknown
     */
    public function getJob(int $jobId): ?array;

    /**
     * Register a job (recurring or one-shot).
     *
     * @param array $job ['module', 'job_key', 'job_type' ('recurring'|'oneshot'),
     *                    'interval_p', 'resolver', 'params' (array|null), 'enabled' (bool)]
     *
     * @return int job id
     */
    public function registerJob(array $job): int;

    /**
     * Try to acquire the per-job lock (FA: GET_LOCK).
     *
     * @param string $lockKey
     *
     * @return bool true when acquired (caller owns release)
     */
    public function acquireLock(string $lockKey): bool;

    public function releaseLock(string $lockKey): void;

    /**
     * Append a run_log row (append-only, BR-007 discipline).
     *
     * @param int   $jobId
     * @param array $row ['status' => 'running'|'ok'|'failed'|'locked'|'skipped',
     *                    'detail' => string|null,
     *                    'started_at' => DateTimeImmutable,
     *                    'finished_at' => DateTimeImmutable|null]
     */
    public function logRun(int $jobId, array $row): void;

    /**
     * Update mutable registry state on completion (success or failure):
     * last_run_at / next_run_at / last_error / fail-count + enable flag.
     *
     * @param int               $jobId
     * @param DateTimeImmutable $now
     * @param DateTimeImmutable|null $nextRunAt null disables further runs (one-shot done)
     * @param string|null       $lastError   non-null => failure backoff applied
     * @param bool              $enabled     final enabled flag (false after 5 fails / one-shot)
     */
    public function completeRun(int $jobId, DateTimeImmutable $now, ?DateTimeImmutable $nextRunAt, ?string $lastError, bool $enabled): void;
}
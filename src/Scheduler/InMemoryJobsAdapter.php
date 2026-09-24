<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Scheduler;

use DateTimeImmutable;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\JobsAdapterInterface;

/**
 * BR-COM-04 FR-COM-04-001 — in-memory JobsAdapter for unit tests + standalone
 * CLI tooling (never FA runtime; the FA adapter lives in ksf_FA_Common's
 * FaJobsAdapter implementing the same contract with db_* calls).
 *
 * Due-window semantics (FR-COM-04-001): listDue() returns ONLY jobs whose
 * next_run_at is in the past <= injected clock. NO NOW(), NO time(), NO
 * date() — the adapter is a pure data holder; the CALLER (JobRunner) owns
 * backoff/next-run computation via the injected clock.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class InMemoryJobsAdapter implements JobsAdapterInterface
{
    /** @var array<int, array<string, mixed>> job_id => job row */
    private $jobs = [];

    /** @var array<int, array<string, mixed>> job_id => appended run rows */
    private $runs = [];

    /** @var array<string, bool> lock key => held */
    private $locks = [];

    /** @var int auto-increment job id */
    private $nextJobId = 1;

    /** @var array<string, mixed> adapter options */
    private $options;

    /**
     * @param array<string, mixed> $options adapter options
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * {@inheritdoc}
     *
     * Returns ONLY jobs whose next_run_at is in the past (<= $now) and whose
     * enabled flag is truthy. Disabled jobs are NEVER due, even when their
     * next_run_at is in the past.
     */
    public function listDue(DateTimeImmutable $now): array
    {
        $due = [];

        foreach ($this->jobs as $jobId => $job) {
            $nextRunAt = $job['next_run_at'] ?? null;
            $enabled   = (bool) ($job['enabled'] ?? false);

            if (!$enabled || !$nextRunAt instanceof DateTimeImmutable) {
                continue;
            }

            if ($nextRunAt <= $now) {
                $job['job_id'] = $jobId;
                $due[] = $job;
            }
        }

        return $due;
    }

    /**
     * {@inheritdoc}
     */
    public function getJob(int $jobId): ?array
    {
        if (!isset($this->jobs[$jobId])) {
            return null;
        }

        $job = $this->jobs[$jobId];
        $job['job_id'] = $jobId;

        return $job;
    }

    /**
     * {@inheritdoc}
     *
     * The registered job row always carries: module, job_key, job_type,
     * interval_p, resolver, params, enabled. next_run_at is computed by the
     * scheduler's injected clock on the first WILL-RUN, not here.
     */
    public function registerJob(array $job): int
    {
        $jobId = $this->nextJobId++;

        $this->jobs[$jobId] = [
            'module'      => (string) ($job['module'] ?? ''),
            'job_key'     => (string) ($job['job_key'] ?? ''),
            'job_type'    => (string) ($job['job_type'] ?? 'recurring'),
            'interval_p'  => (string) ($job['interval_p'] ?? ''),
            'resolver'    => (string) ($job['resolver'] ?? ''),
            'params'      => $job['params'] ?? null,
            'enabled'     => (bool) ($job['enabled'] ?? true),
            'next_run_at' => $job['next_run_at'] ?? null,
            'fail_count'  => 0,
        ];

        return $jobId;
    }

    /**
     * {@inheritdoc}
     *
     * Appends an append-only run row (BR-COM-04 FR-COM-04-005): a job_id may
     * have multiple rows; rows are NEVER updated in place.
     */
    public function logRun(int $jobId, array $row): void
    {
        $row['job_id'] = $jobId;
        if (!isset($row['source'])) {
            $row['source'] = 'cli';
        }
        $this->runs[$jobId][] = $row;
    }

    /**
     * {@inheritdoc}
     *
     * Stores only scheduler-owned mutable fields. NEVER touches next_run_at
     * here — completeRun's caller (JobRunner) computes next_run_at with the
     * injected clock and passes it explicitly. This keeps the adapter free of
     * NOW()/time()/date().
     *
     * fail_count semantics (FR-COM-04-004): a failure run increments the
     * counter; any successful run resets it to 0. The auto-disable threshold
     * (5) is JobRunner policy — the adapter only stores the counter.
     */
    public function completeRun(
        int $jobId,
        DateTimeImmutable $now,
        ?DateTimeImmutable $nextRunAt,
        ?string $lastError,
        bool $enabled
    ): void {
        if (!isset($this->jobs[$jobId])) {
            return;
        }

        $failCount = (int) ($this->jobs[$jobId]['fail_count'] ?? 0);
        if ($lastError !== null) {
            $failCount++;
        } else {
            $failCount = 0;
        }

        $this->jobs[$jobId]['enabled']     = $enabled;
        $this->jobs[$jobId]['last_error']  = $lastError;
        $this->jobs[$jobId]['last_run_at'] = $now;
        $this->jobs[$jobId]['fail_count']  = $failCount;

        if ($nextRunAt !== null) {
            $this->jobs[$jobId]['next_run_at'] = $nextRunAt;
        } else {
            unset($this->jobs[$jobId]['next_run_at']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function acquireLock(string $lockKey): bool
    {
        if (isset($this->locks[$lockKey])) {
            return false;
        }

        $this->locks[$lockKey] = true;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function releaseLock(string $lockKey): void
    {
        unset($this->locks[$lockKey]);
    }

    /**
     * Expose the run log (test aid, not on the interface): returns append-only
     * runs per job.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getRuns(): array
    {
        return $this->runs;
    }
}

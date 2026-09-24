<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Scheduler;

use DateTimeImmutable;
use Throwable;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\ClockInterface;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\JobsAdapterInterface;

final class JobRunner
{
    public const MAX_CONSECUTIVE_FAILURES = 5;

    private JobsAdapterInterface $adapter;
    private ClockInterface $clock;
    private JobResolverRegistry $resolvers;

    public function __construct(
        JobsAdapterInterface $adapter,
        ClockInterface $clock,
        ?JobResolverRegistry $resolvers = null
    ) {
        $this->adapter   = $adapter;
        $this->clock     = $clock;
        $this->resolvers = $resolvers ?? new JobResolverRegistry();
    }

    public function consume(int $limit = 0, string $source = 'cli'): array
    {
        $now  = $this->clock->now();
        $runs = [];
        foreach ($this->adapter->listDue($now) as $job) {
            if ($limit > 0 && \count($runs) >= $limit) {
                break;
            }
            $jobId  = (int) $job['job_id'];
            $lockKey = 'ksf_wf_job:' . $jobId;

            if (!$this->adapter->acquireLock($lockKey)) {
                $this->adapter->logRun($jobId, [
                    'status'      => 'locked',
                    'detail'      => $lockKey,
                    'source'      => $source,
                    'started_at'  => $now,
                    'finished_at' => $now,
                ]);
                $runs[] = ['job_id' => $jobId, 'status' => 'locked', 'source' => $source];
                continue;
            }

            try {
                $this->adapter->logRun($jobId, [
                    'status'      => 'running',
                    'detail'      => null,
                    'source'      => $source,
                    'started_at'  => $now,
                    'finished_at' => null,
                ]);

                $resolver = $this->resolvers->resolve((string) $job['resolver']);
                if ($resolver === null) {
                    throw new \RuntimeException(
                        'Unknown job resolver: ' . $job['resolver']
                    );
                }
                $resolver($this->params($job));

                $this->adapter->logRun($jobId, [
                    'status'      => 'ok',
                    'detail'      => null,
                    'source'      => $source,
                    'started_at'  => $now,
                    'finished_at' => $now,
                ]);
                $this->complete($job, $jobId, $now, null);
                $runs[] = ['job_id' => $jobId, 'status' => 'ok', 'source' => $source];
            } catch (JobSkippedException $e) {
                $this->adapter->logRun($jobId, [
                    'status'      => 'skipped',
                    'detail'      => $e->getMessage(),
                    'source'      => $source,
                    'started_at'  => $now,
                    'finished_at' => $now,
                ]);
                $this->complete($job, $jobId, $now, null);
                $runs[] = ['job_id' => $jobId, 'status' => 'skipped', 'source' => $source];
            } catch (Throwable $e) {
                $failCount = $this->failCount($job) + 1;
                $this->adapter->logRun($jobId, [
                    'status'      => 'failed',
                    'detail'      => $e->getMessage(),
                    'source'      => $source,
                    'started_at'  => $now,
                    'finished_at' => $now,
                ]);
                $this->complete(
                    $job,
                    $jobId,
                    $now,
                    $e->getMessage(),
                    $failCount >= self::MAX_CONSECUTIVE_FAILURES
                );
                $runs[] = ['job_id' => $jobId, 'status' => 'failed', 'source' => $source];
            } finally {
                $this->adapter->releaseLock($lockKey);
            }
        }
        return $runs;
    }

    private function nextRunAt(array $job, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $interval = (string) ($job['interval_p'] ?? '');
        if ($interval === '') {
            return null;
        }

        return $now->modify('+' . $interval);
    }

    /**
     * Job params are an array in-memory and a JSON string at FA runtime;
     * normalize both to an array for resolvers.
     */
    private function params(array $job): array
    {
        $raw = $job['params'] ?? [];
        if (\is_string($raw) && $raw !== '') {
            $decoded = \json_decode($raw, true);
            return \is_array($decoded) ? $decoded : [];
        }
        return \is_array($raw) ? $raw : [];
    }

    /**
     * Finalize a run in the registry.
     *
     * Success — recurring: keep enabled, schedule the next interval. Success —
     * oneshot: disable (next_run_at null). Failure: backoff one interval;
     * disable when the consecutive counter hits the threshold.
     */
    private function complete(array $job, int $jobId, DateTimeImmutable $now, ?string $lastError, bool $disabled = false): void
    {
        if ($lastError !== null) {
            $this->adapter->completeRun(
                $jobId,
                $now,
                $this->nextRunAt($job, $now),
                $lastError,
                !$disabled
            );
            return;
        }

        $isOneShot = (string) ($job['job_type'] ?? '') === 'oneshot';
        $this->adapter->completeRun(
            $jobId,
            $now,
            $isOneShot ? null : $this->nextRunAt($job, $now),
            null,
            !$isOneShot
        );
    }

    private function failCount(array $job): int
    {
        return (int) ($job['fail_count'] ?? 0);
    }
}
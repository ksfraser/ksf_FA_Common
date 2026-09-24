<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\ClockInterface;
use ksfraser\FrontAccounting\Common\Scheduler\InMemoryJobsAdapter;
use ksfraser\FrontAccounting\Common\Scheduler\JobResolverRegistry;
use ksfraser\FrontAccounting\Common\Scheduler\JobRunner;

final class JobRunnerGuardClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable $now)
    {
        $this->now = $now;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class JobRunnerGuardTest extends TestCase
{
    private function dueJob(InMemoryJobsAdapter $adapter, array $job, DateTimeImmutable $due): int
    {
        $job['enabled'] = true;
        $jobId = $adapter->registerJob($job);
        $adapter->completeRun($jobId, $due, $due, null, true);

        return $jobId;
    }

    public function testHeldLockLogsLockedRowAndDoesNotRunResolver(): void
    {
        $adapter   = new InMemoryJobsAdapter();
        $registry  = new JobResolverRegistry();
        $resolved  = [];
        $registry->register('hrm.accrual', function (array $params) use (&$resolved): void {
            $resolved[] = $params;
        });

        $jobId = $this->dueJob($adapter, [
            'module'      => 'ksf_FA_HRM',
            'job_key'     => 'accrual.monthly',
            'job_type'    => 'recurring',
            'resolver'    => 'hrm.accrual',
            'interval_p'  => '1 hour',
            'params'      => null,
        ], new DateTimeImmutable('2026-09-21 00:00:00'));

        $adapter->acquireLock('ksf_wf_job:' . $jobId);

        $runner = new JobRunner(
            $adapter,
            new JobRunnerGuardClock(new DateTimeImmutable('2026-09-21 02:00:00')),
            $registry
        );
        $runs = $runner->consume();

        $this->assertSame([], $resolved);
        $rows = $adapter->getRuns()[$jobId] ?? [];
        $this->assertCount(1, $rows);
        $this->assertSame('locked', $rows[0]['status']);
        $this->assertSame('ksf_wf_job:' . $jobId, $rows[0]['detail']);
    }

    public function testDueJobRunsResolverWritesOkRowAndRecomputesNext(): void
    {
        $adapter   = new InMemoryJobsAdapter();
        $registry  = new JobResolverRegistry();
        $resolved  = [];
        $registry->register('hrm.accrual', function (array $params) use (&$resolved): void {
            $resolved[] = $params;
        });

        $jobId = $this->dueJob($adapter, [
            'module'      => 'ksf_FA_HRM',
            'job_key'     => 'accrual.monthly',
            'job_type'    => 'recurring',
            'resolver'    => 'hrm.accrual',
            'interval_p'  => '1 hour',
            'params'      => ['cost_center' => 'CC-10'],
        ], new DateTimeImmutable('2026-09-21 00:00:00'));

        $now = new DateTimeImmutable('2026-09-21 02:00:00');
        $runs = (new JobRunner(
            $adapter,
            new JobRunnerGuardClock($now),
            $registry
        ))->consume();

        $this->assertSame([['cost_center' => 'CC-10']], $resolved);
        $rows = $adapter->getRuns()[$jobId] ?? [];
        $this->assertSame('ok', end($rows)['status']);
        $this->assertTrue($adapter->acquireLock('ksf_wf_job:' . $jobId));
        $this->assertEquals($now->modify('+1 hour'), $adapter->getJob($jobId)['next_run_at']);
    }

    public function testThrowingResolverWritesFailedRowWithoutRaising(): void
    {
        $adapter   = new InMemoryJobsAdapter();
        $registry  = new JobResolverRegistry();
        $registry->register('hrm.bad', function (array $params): void {
            throw new \RuntimeException('resolver boom');
        });

        $jobId = $this->dueJob($adapter, [
            'module'      => 'ksf_FA_HRM',
            'job_key'     => 'accrual.monthly',
            'job_type'    => 'recurring',
            'resolver'    => 'hrm.bad',
            'interval_p'  => '1 hour',
            'params'      => null,
        ], new DateTimeImmutable('2026-09-21 00:00:00'));

        $now = new DateTimeImmutable('2026-09-21 02:00:00');
        $runs = (new JobRunner(
            $adapter,
            new JobRunnerGuardClock($now),
            $registry
        ))->consume();

        $rows = $adapter->getRuns()[$jobId] ?? [];
        $this->assertSame('failed', end($rows)['status']);
        $this->assertSame('resolver boom', $adapter->getJob($jobId)['last_error']);
    }
}
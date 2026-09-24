<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\ClockInterface;
use ksfraser\FrontAccounting\Common\Scheduler\InMemoryJobsAdapter;
use ksfraser\FrontAccounting\Common\Scheduler\JobResolverRegistry;
use ksfraser\FrontAccounting\Common\Scheduler\JobRunner;

final class JobRunnerMutableClock implements ClockInterface
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

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}

final class JobRunnerTypesTest extends TestCase
{
    private function dueJob(InMemoryJobsAdapter $adapter, array $job, DateTimeImmutable $due): int
    {
        $job['enabled'] = true;
        $jobId = $adapter->registerJob($job);
        $adapter->completeRun($jobId, $due, $due, null, true);

        return $jobId;
    }

    private function runner(InMemoryJobsAdapter $adapter, JobRunnerMutableClock $clock, JobResolverRegistry $registry): JobRunner
    {
        return new JobRunner($adapter, $clock, $registry);
    }

    public function testOneshotJobDisablesItselfOnSuccess(): void
    {
        $adapter  = new InMemoryJobsAdapter();
        $registry = new JobResolverRegistry();
        $registry->register('wf.trans', function (array $params): void {
        });

        $jobId = $this->dueJob($adapter, [
            'module'     => 'ksf_FA_HRM',
            'job_key'    => 'wf:trans:leave:7:submitted:approved',
            'job_type'   => 'oneshot',
            'resolver'   => 'wf.trans',
            'interval_p' => '',
            'params'     => null,
        ], new DateTimeImmutable('2026-09-21 12:00:00'));

        $clock = new JobRunnerMutableClock(new DateTimeImmutable('2026-09-21 12:00:00'));
        $this->runner($adapter, $clock, $registry)->consume();

        $job = $adapter->getJob($jobId);
        $this->assertFalse($job['enabled']);
        $this->assertNull($job['next_run_at'] ?? null);
    }

    public function testRecurringJobStaysEnabledAfterSuccess(): void
    {
        $adapter  = new InMemoryJobsAdapter();
        $registry = new JobResolverRegistry();
        $registry->register('hrm.accrual', function (array $params): void {
        });

        $jobId = $this->dueJob($adapter, [
            'module'     => 'ksf_FA_HRM',
            'job_key'    => 'accrual.monthly',
            'job_type'   => 'recurring',
            'resolver'   => 'hrm.accrual',
            'interval_p' => '1 hour',
            'params'     => null,
        ], new DateTimeImmutable('2026-09-21 11:00:00'));

        $clock = new JobRunnerMutableClock(new DateTimeImmutable('2026-09-21 12:00:00'));
        $this->runner($adapter, $clock, $registry)->consume();

        $job = $adapter->getJob($jobId);
        $this->assertTrue($job['enabled']);
        $this->assertEquals($clock->now()->modify('+1 hour'), $job['next_run_at']);
    }

    public function testThrowingResolverBacksOffAndCountsFailure(): void
    {
        $adapter  = new InMemoryJobsAdapter();
        $registry = new JobResolverRegistry();
        $registry->register('hrm.bad', function (array $params): void {
            throw new \RuntimeException('boom');
        });

        $jobId = $this->dueJob($adapter, [
            'module'     => 'ksf_FA_HRM',
            'job_key'    => 'accrual.monthly',
            'job_type'   => 'recurring',
            'resolver'   => 'hrm.bad',
            'interval_p' => '1 hour',
            'params'     => null,
        ], new DateTimeImmutable('2026-09-21 11:00:00'));

        $clock = new JobRunnerMutableClock(new DateTimeImmutable('2026-09-21 12:00:00'));
        $this->runner($adapter, $clock, $registry)->consume();

        $job = $adapter->getJob($jobId);
        $this->assertSame(1, $job['fail_count']);
        $this->assertTrue($job['enabled']);
        $this->assertEquals($clock->now()->modify('+1 hour'), $job['next_run_at']);
        $this->assertEquals('boom', $adapter->getRuns()[$jobId][1]['detail'] ?? $job['last_error']);
    }

    public function testFiveConsecutiveFailuresDisableJob(): void
    {
        $adapter  = new InMemoryJobsAdapter();
        $registry = new JobResolverRegistry();
        $registry->register('hrm.bad', function (array $params): void {
            throw new \RuntimeException('boom');
        });

        $jobId = $this->dueJob($adapter, [
            'module'     => 'ksf_FA_HRM',
            'job_key'    => 'accrual.monthly',
            'job_type'   => 'recurring',
            'resolver'   => 'hrm.bad',
            'interval_p' => '1 hour',
            'params'     => null,
        ], new DateTimeImmutable('2026-09-21 11:00:00'));

        $clock = new JobRunnerMutableClock(new DateTimeImmutable('2026-09-21 12:00:00'));
        $runner = $this->runner($adapter, $clock, $registry);

        for ($i = 0; $i < 5; $i++) {
            $runner->consume();
            $clock->advance('+1 hour');
        }

        $job = $adapter->getJob($jobId);
        $this->assertFalse($job['enabled']);
        $this->assertSame(5, $job['fail_count']);

        $clock->advance('+1 hour');
        $this->assertSame([], $runner->consume());
    }

    public function testSuccessAfterFailuresResetsFailCount(): void
    {
        $adapter  = new InMemoryJobsAdapter();
        $registry = new JobResolverRegistry();
        $success  = false;
        $registry->register('hrm.flaky', function (array $params) use (&$success): void {
            if (!$success) {
                throw new \RuntimeException('boom');
            }
        });

        $jobId = $this->dueJob($adapter, [
            'module'     => 'ksf_FA_HRM',
            'job_key'    => 'accrual.monthly',
            'job_type'   => 'recurring',
            'resolver'   => 'hrm.flaky',
            'interval_p' => '1 hour',
            'params'     => null,
        ], new DateTimeImmutable('2026-09-21 11:00:00'));

        $clock  = new JobRunnerMutableClock(new DateTimeImmutable('2026-09-21 12:00:00'));
        $runner = $this->runner($adapter, $clock, $registry);

        $runner->consume();
        $clock->advance('+1 hour');

        $success = true;
        $runner->consume();

        $job = $adapter->getJob($jobId);
        $this->assertSame(0, $job['fail_count']);
        $this->assertTrue($job['enabled']);
        $this->assertEquals($clock->now()->modify('+1 hour'), $job['next_run_at']);
    }
}
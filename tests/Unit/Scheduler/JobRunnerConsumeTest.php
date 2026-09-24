<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\ClockInterface;
use ksfraser\FrontAccounting\Common\Scheduler\InMemoryJobsAdapter;
use ksfraser\FrontAccounting\Common\Scheduler\JobResolverRegistry;
use ksfraser\FrontAccounting\Common\Scheduler\JobRunner;

final class JobRunnerInlineClock implements ClockInterface
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

final class JobRunnerConsumeTest extends TestCase
{
    public function testConsumeRunsOnlyDueJob(): void
    {
        $adapter  = new InMemoryJobsAdapter();
        $registry = new JobResolverRegistry();
        $registry->register('hrm.accrual', function (array $params): void {
        });

        $jobId = $adapter->registerJob([
            'module'     => 'ksf_FA_HRM',
            'job_key'    => 'accrual.monthly',
            'resolver'   => 'hrm.accrual',
            'interval_p' => '1 hour',
            'enabled'    => true,
        ]);

        $adapter->completeRun(
            $jobId,
            new DateTimeImmutable('2026-09-21 00:00:00'),
            new DateTimeImmutable('2026-09-21 01:00:00'),
            null,
            true
        );

        $runs = (new JobRunner(
            $adapter,
            new JobRunnerInlineClock(new DateTimeImmutable('2026-09-21 02:00:00')),
            $registry
        ))->consume();

        $this->assertCount(1, $runs);
        $this->assertSame('ok', $runs[0]['status']);
    }

    public function testConsumeWithNoDueJobsMakesNoRuns(): void
    {
        $adapter = new InMemoryJobsAdapter();

        $runs = (new JobRunner(
            $adapter,
            new JobRunnerInlineClock(new DateTimeImmutable('2026-09-21 00:29:59'))
        ))->consume();

        $this->assertSame([], $runs);
    }
}

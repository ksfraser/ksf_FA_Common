<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\ClockInterface;
use ksfraser\FrontAccounting\Common\Scheduler\InMemoryJobsAdapter;
use ksfraser\FrontAccounting\Common\Scheduler\JobResolverRegistry;
use ksfraser\FrontAccounting\Common\Scheduler\JobRunner;
use ksfraser\FrontAccounting\Common\Scheduler\WebTickle;

final class WebTickleClock implements ClockInterface
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

final class WebTickleTest extends TestCase
{
    private InMemoryJobsAdapter $adapter;
    private JobResolverRegistry $registry;
    private array $resolved;

    protected function setUp(): void
    {
        $this->adapter   = new InMemoryJobsAdapter();
        $this->registry  = new JobResolverRegistry();
        $this->resolved  = [];
        $this->registry->register('hrm.ok', function (array $params): void {
            $this->resolved[] = $params;
        });
    }

    private function dueJob(string $key, DateTimeImmutable $due): int
    {
        $jobId = $this->adapter->registerJob([
            'module'      => 'ksf_FA_HRM',
            'job_key'     => $key,
            'job_type'    => 'recurring',
            'resolver'    => 'hrm.ok',
            'interval_p'  => '1 hour',
            'params'      => null,
            'enabled'     => true,
        ]);
        $this->adapter->completeRun($jobId, $due, $due, null, true);

        return $jobId;
    }

    private function runner(): JobRunner
    {
        return new JobRunner(
            $this->adapter,
            new WebTickleClock(new DateTimeImmutable('2026-09-21 12:00:00')),
            $this->registry
        );
    }

    public function testTickleSkipsEntireRunWhenGlobalLockIsHeld(): void
    {
        $this->dueJob('job.a', new DateTimeImmutable('2026-09-21 11:00:00'));
        $this->adapter->acquireLock('ksf-wf:lock');

        $results = (new WebTickle($this->runner(), $this->adapter, 5))->tickle();

        $this->assertSame([], $results);
        $this->assertSame([], $this->resolved);
    }

    public function testTickleRunsAtMostCapDueJobs(): void
    {
        $due = new DateTimeImmutable('2026-09-21 11:00:00');
        $this->dueJob('job.a', $due);
        $this->dueJob('job.b', $due);
        $this->dueJob('job.c', $due);

        $results = (new WebTickle($this->runner(), $this->adapter, 2))->tickle();

        $this->assertCount(2, $results);
        $this->assertCount(2, $this->resolved);
        $this->assertSame('web', $results[0]['source']);
    }

    public function testTickleReleasesGlobalLockAfterRun(): void
    {
        $this->dueJob('job.a', new DateTimeImmutable('2026-09-21 11:00:00'));

        (new WebTickle($this->runner(), $this->adapter, 5))->tickle();

        $this->assertTrue($this->adapter->acquireLock('ksf-wf:lock'));
    }
}
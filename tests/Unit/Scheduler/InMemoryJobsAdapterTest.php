<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Scheduler;

use DateTimeImmutable;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\JobsAdapterInterface;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\ClockInterface;
use ksfraser\FrontAccounting\Common\Scheduler\InMemoryJobsAdapter;
use ksfraser\FrontAccounting\Common\Tests\Unit\Scheduler\FakeClock;
use PHPUnit\Framework\TestCase;

/**
 * BR-COM-04 FR-COM-04-001 — JobsAdapter::listDue must return ONLY jobs whose
 * next_run_at has passed according to the injected clock (never NOW()).
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class InMemoryJobsAdapterTest extends TestCase
{
    public function testDueWindowIncludesExactlyOnlyDueJobs(): void
    {
        $adapter = new InMemoryJobsAdapter([
            'interval_p' => '1 hour',
            'module'     => 'ksf_FA_HRM',
        ]);

        $jobA = $adapter->registerJob([
            'module'     => 'ksf_FA_HRM',
            'job_key'    => 'accrual.monthly',
            'job_type'   => 'recurring',
            'interval_p' => '1 hour',
            'resolver'   => 'hrm.accrual',
            'params'     => ['cost_center' => 'CC-10'],
            'enabled'    => true,
        ]);
        $adapter->completeRun(
            $jobA,
            new DateTimeImmutable('2026-09-21 02:00:00'),
            new DateTimeImmutable('2026-09-21 03:00:00'),
            null,
            true
        );

        $jobB = $adapter->registerJob([
            'module'     => 'ksf_FA_HRM',
            'job_key'    => 'leave-expiry.q',
            'job_type'   => 'recurring',
            'interval_p' => '3 days',
            'resolver'   => 'hrm.leave_expiry',
            'params'     => null,
            'enabled'    => true,
        ]);
        $adapter->completeRun(
            $jobB,
            new DateTimeImmutable('2026-09-18 00:00:00'),
            new DateTimeImmutable('2026-09-25 00:00:00'),
            null,
            true
        );

        $due = $adapter->listDue(new DateTimeImmutable('2026-09-21 03:00:00'));

        $this->assertCount(1, $due);
        $this->assertSame($jobA, (int) $due[0]['job_id']);
    }

    /**
     * FR-COM-04-001 — a disabled job is never due, even when its next_run_at
     * is in the past.
     */
    public function testDisabledJobIsNeverDue(): void
    {
        $adapter = new InMemoryJobsAdapter(['interval_p' => '1 hour']);
        $jobId = $adapter->registerJob([
            'module'     => 'ksf_FA_Calendar',
            'job_key'    => 'quote-expiry.due',
            'job_type'   => 'recurring',
            'interval_p' => '1 hour',
            'resolver'   => 'crm.quote_expiry',
            'params'     => null,
            'enabled'    => false,
        ]);
        $adapter->completeRun(
            $jobId,
            new DateTimeImmutable('2026-09-21 02:00:00'),
            new DateTimeImmutable('2026-09-21 03:00:00'),
            null,
            false
        );

        $this->assertSame([], $adapter->listDue(new DateTimeImmutable('2026-09-21 03:00:00')));
    }
}

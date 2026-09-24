<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\ClockInterface;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\JobsAdapterInterface;
use ksfraser\FrontAccounting\Common\Scheduler\InMemoryJobsAdapter;
use ksfraser\FrontAccounting\Common\Scheduler\JobResolverRegistry;
use ksfraser\FrontAccounting\Common\Scheduler\JobRunner;
use ksfraser\FrontAccounting\Common\Scheduler\WaitTransition;
use ksfraser\FrontAccounting\Common\Workflow\InMemoryStateStore;
use ksfraser\FrontAccounting\Common\Workflow\ProcessDefinition;
use ksfraser\FrontAccounting\Common\Workflow\StateMachine;

final class WaitTransitionClock implements ClockInterface
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

/**
 * The time-wait wire to BR-COM-02 (FR-COM-04-007).
 *
 * A transition edge carrying `wait` arms a one-shot scheduler job keyed
 * wf:trans:<recordType>:<instanceId>:<from>:<to>. When the deadline passes the
 * resolver wakes the state machine (actor 'system', reason 'deadline'); if the
 * instance has already left `from` the job is an idempotent no-op logged as
 * 'skipped' — the scheduler wakes the state machine, never the reverse.
 *
 * @BABOK Related: FR-COM-04-007, BR-COM-02 (time-gating lease)
 */
final class WaitTransitionTest extends TestCase
{
    private const NOW = '2026-09-21 12:00:00';

    private InMemoryJobsAdapter $adapter;
    private InMemoryStateStore $store;
    private StateMachine $machine;
    private ProcessDefinition $def;
    private WaitTransition $wait;

    protected function setUp(): void
    {
        $this->adapter = new InMemoryJobsAdapter();
        $this->store   = new InMemoryStateStore();

        $this->def = ProcessDefinition::fromArray([
            'name'          => 'leave_request.approval',
            'record'        => 'leave_request',
            'record_prefix' => 'ksf_FA_HRM',
            'states'        => ['draft', 'submitted', 'pending', 'approved', 'cancelled'],
            'initial'       => 'draft',
            'transitions'   => [
                ['from' => 'draft', 'to' => 'submitted', 'trigger' => 'user'],
                ['from' => 'submitted', 'to' => 'pending', 'trigger' => 'auto'],
                ['from' => 'pending', 'to' => 'approved', 'trigger' => 'time',
                 'wait' => '3 days'],
                ['from' => 'pending', 'to' => 'cancelled', 'trigger' => 'user'],
            ],
        ]);

        $this->machine = new StateMachine($this->store);
        $this->machine->register($this->def);
        $this->wait = new WaitTransition($this->machine, $this->adapter);
    }

    public function testArmRegistersOneshotJobWithResolvedFireTime(): void
    {
        $jobId = $this->wait->arm(
            $this->def,
            'LR-1',
            ['from' => 'pending', 'to' => 'approved', 'wait' => '3 days'],
            new DateTimeImmutable(self::NOW)
        );

        $this->assertIsInt($jobId);

        $job = $this->adapter->getJob($jobId);
        $this->assertNotNull($job);
        $this->assertSame('leave_request', $job['module']);
        $this->assertSame('wf:trans:leave_request:LR-1:pending:approved', $job['job_key']);
        $this->assertSame('oneshot', $job['job_type']);
        $this->assertSame('', $job['interval_p']);
        $this->assertSame(WaitTransition::RESOLVER_KEY, $job['resolver']);
        $this->assertTrue($job['enabled']);
        $this->assertEquals(
            new DateTimeImmutable('2026-09-24 12:00:00'),
            $job['next_run_at']
        );
        $this->assertSame('pending', $job['params']['from']);
        $this->assertSame('approved', $job['params']['to']);
        $this->assertSame('LR-1', $job['params']['record_id']);
    }

    public function testArmIgnoresEdgeWithoutWait(): void
    {
        $jobId = $this->wait->arm(
            $this->def,
            'LR-1',
            ['from' => 'draft', 'to' => 'submitted'],
            new DateTimeImmutable(self::NOW)
        );

        $this->assertNull($jobId);
        $this->assertSame([], $this->adapter->listDue(new DateTimeImmutable(self::NOW)));
    }

    public function testResolverWakesStateMachineAtDeadline(): void
    {
        $this->store->saveState([
            'record_type' => 'leave_request',
            'record_id'   => 'LR-1',
            'state'       => 'pending',
        ]);

        $jobId = $this->wait->arm(
            $this->def,
            'LR-1',
            ['from' => 'pending', 'to' => 'approved', 'wait' => '3 days'],
            new DateTimeImmutable(self::NOW)
        );

        $registry = new JobResolverRegistry();
        $registry->register(WaitTransition::RESOLVER_KEY, $this->wait);
        $runner = new JobRunner($this->adapter, new WaitTransitionClock(new DateTimeImmutable('2026-09-24 12:00:05')), $registry);

        $runs = $runner->consume();

        $this->assertSame('approved', $this->machine->currentState('leave_request', 'LR-1'));
        $this->assertCount(1, $runs);
        $this->assertSame('ok', $runs[0]['status']);

        $history = $this->store->listHistory('leave_request', 'LR-1');
        $this->assertCount(1, $history);
        $this->assertSame('pending', $history[0]['from']);
        $this->assertSame('approved', $history[0]['to']);
        $this->assertSame('system', $history[0]['actor']);
        $this->assertSame('deadline', $history[0]['reason']);
    }

    public function testResolverSkipsWhenInstanceAlreadyLeftFrom(): void
    {
        $this->store->saveState([
            'record_type' => 'leave_request',
            'record_id'   => 'LR-1',
            'state'       => 'cancelled',
        ]);

        $jobId = $this->wait->arm(
            $this->def,
            'LR-1',
            ['from' => 'pending', 'to' => 'approved', 'wait' => '3 days'],
            new DateTimeImmutable(self::NOW)
        );

        $registry = new JobResolverRegistry();
        $registry->register(WaitTransition::RESOLVER_KEY, $this->wait);
        $runner = new JobRunner($this->adapter, new WaitTransitionClock(new DateTimeImmutable('2026-09-24 12:00:05')), $registry);

        $runs = $runner->consume();

        $this->assertSame('cancelled', $this->machine->currentState('leave_request', 'LR-1'));
        $this->assertCount(1, $runs);
        $this->assertSame('skipped', $runs[0]['status']);
        $rows = $this->adapter->getRuns()[$jobId];
        $this->assertSame('skipped', end($rows)['status']);
        $this->assertSame([], $this->store->listHistory('leave_request', 'LR-1'));
    }
}
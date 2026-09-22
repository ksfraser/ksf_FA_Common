<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Workflow;

use ksfraser\FrontAccounting\Common\Workflow\CalcRegistry;
use ksfraser\FrontAccounting\Common\Workflow\StepEngine;
use ksfraser\FrontAccounting\Common\Workflow\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

class StepEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowRegistry::resetInstance();
    }

    protected function tearDown(): void
    {
        WorkflowRegistry::resetInstance();
        parent::tearDown();
    }

    public function testSetVerbAppliesToDto(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $registry->register('leave_request', 'after_save', [
            ['criteria' => [['status', 'eq', 'submitted']],
             'do'       => [['set', 'status', '=', 'pending']]],
        ]);

        $engine = new StepEngine($registry, new CalcRegistry());
        $result = $engine->run('leave_request', 'after_save', ['id' => 1, 'status' => 'submitted']);

        $this->assertSame('pending', $result['dto']['status']);
        $this->assertTrue($result['changed']);
    }

    public function testCriteriaNotMatchedSkipsStep(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $registry->register('leave_request', 'after_save', [
            ['criteria' => [['status', 'eq', 'submitted']],
             'do'       => [['set', 'status', '=', 'pending']]],
        ]);

        $engine = new StepEngine($registry, new CalcRegistry());
        $result = $engine->run('leave_request', 'after_save', ['id' => 1, 'status' => 'draft']);

        $this->assertSame('draft', $result['dto']['status']);
        $this->assertFalse($result['changed']);
    }

    public function testThenResolverWritesResultAndSetReadsIt(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $calc     = new CalcRegistry();
        $calc->register('hrm.approval_chain', function (array $dto, array $ctx) {
            return ['person_id' => 17, 'approved' => false];
        });

        $registry->register('leave_request', 'after_save', [
            ['criteria' => [['status', 'eq', 'submitted']],
             'then'     => ['resolver' => 'hrm.approval_chain', 'method' => 'nextStep'],
             'do'       => [['set', 'current_approver', '=', 'result.person_id'],
                            ['set', 'current_approver_set', '=', 'result.approved']]],
        ]);

        $engine = new StepEngine($registry, $calc);
        $result = $engine->run('leave_request', 'after_save', ['id' => 1, 'status' => 'submitted']);

        $this->assertSame(17, $result['dto']['current_approver']);
        $this->assertFalse($result['dto']['current_approver_set']);
    }

    public function testElseBranchRunsWhenCriteriaFails(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $registry->register('leave_request', 'after_save', [
            ['criteria' => [['status', 'eq', 'submitted']],
             'do'       => [['set', 'status', '=', 'pending']],
             'else'     => [['set', 'status', '=', 'skipped']]],
        ]);

        $engine = new StepEngine($registry, new CalcRegistry());
        $result = $engine->run('leave_request', 'after_save', ['id' => 1, 'status' => 'draft']);

        $this->assertSame('skipped', $result['dto']['status']);
    }

    public function testCreateVerbInvokesCreatorAndChainsAfterSave(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $created  = null;

        $creator = function (string $type, array $fields) use (&$created) {
            $created = ['id' => 99, 'type' => $type, ...$fields];
            return $created;
        };

        // The chained after_save on the created type sets a flag.
        $registry->register('approval_task', 'after_save', [
            ['criteria' => [['type', 'eq', 'approval_task']],
             'do'       => [['set', 'chained', '=', true]]],
        ]);

        $registry->register('leave_request', 'after_save', [
            ['do' => [['create', 'approval_task', ['copy' => ['request_id', 'current_approver']]]]],
        ]);

        $engine = new StepEngine($registry, new CalcRegistry());
        $engine->setCreator($creator);
        $result = $engine->run('leave_request', 'after_save', [
            'id' => 1, 'request_id' => 'LR-1', 'current_approver' => 17,
        ]);

        $this->assertNotNull($created);
        $this->assertSame('approval_task', $created['type']);
        $this->assertSame('LR-1', $created['request_id']);
        $this->assertSame(17, $created['current_approver']);

        $createdList = $engine->getCreated();
        $this->assertCount(1, $createdList);
        $this->assertTrue($createdList[0]['chained']);
        $this->assertTrue($result['changed']);
    }

    public function testBroadcastVerbCallsDispatcher(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $events   = [];

        $registry->register('order', 'after_save', [
            ['do' => [['broadcast', 'gpg_sign', ['email' => 'dto.contact_email', 'id' => 'dto.id']]]],
        ]);

        $engine = new StepEngine($registry, new CalcRegistry());
        $engine->setBroadcaster(function (string $event, array $payload) use (&$events) {
            $events[] = [$event, $payload];
        });

        $engine->run('order', 'after_save', ['id' => 42, 'contact_email' => 'a@b.c']);

        $this->assertCount(1, $events);
        $this->assertSame('gpg_sign', $events[0][0]);
        $this->assertSame(['email' => 'a@b.c', 'id' => 42], $events[0][1]);
    }

    public function testEndStopsRemainingSteps(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $registry->register('leave_request', 'after_save', [
            ['criteria' => [['status', 'eq', 'noop']], 'do' => [['end']]],
            ['do'       => [['set', 'after_end', '=', true]]],
        ]);

        $engine = new StepEngine($registry, new CalcRegistry());
        $result = $engine->run('leave_request', 'after_save', ['id' => 1, 'status' => 'noop']);

        $this->assertTrue($result['stopped']);
        $this->assertArrayNotHasKey('after_end', $result['dto']);
    }

    public function testDepthGuardStopsRun(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $registry->setMaxDepth(2);
        $calc     = new CalcRegistry();

        // self-create loop: leave_request.after_save creates another leave_request
        $creator = function (string $type, array $fields) {
            return ['id' => 2, 'status' => 'submitted'];
        };
        $registry->register('leave_request', 'after_save', [
            ['do' => [['create', 'leave_request', []]]],
        ]);

        $engine = new StepEngine($registry, $calc);
        $engine->setCreator($creator);
        $result = $engine->run('leave_request', 'after_save', ['id' => 1, 'status' => 'submitted']);

        // Depth exceeded must be reflected somewhere (stopped true at some level).
        $this->assertTrue($result['stopped']);

        // Guards logged to audit
        $guards = \array_filter($engine->getAuditLog(), function (array $row) {
            return $row['guard'] !== null;
        });
        $this->assertNotEmpty($guards);
    }

    public function testVisitedGuardCatchesFeedbackLoop(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $calc     = new CalcRegistry();

        // after_save creates a NEW dto with same id (simulates same-record re-entry)
        $creator = function (string $type, array $fields) {
            return ['id' => 1, 'status' => 'submitted'];
        };
        $registry->register('leave_request', 'after_save', [
            ['do' => [['create', 'leave_request', []]]],
        ]);

        $engine = new StepEngine($registry, $calc);
        $engine->setCreator($creator);
        $result = $engine->run('leave_request', 'after_save', ['id' => 1, 'status' => 'submitted']);

        $this->assertTrue($result['stopped']);
    }

    public function testThrowingResolverDoesNotAbortChain(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $calc     = new CalcRegistry();
        $calc->register('bombs', function () {
            throw new \RuntimeException('boom');
        });
        $registry->register('leave_request', 'after_save', [
            ['then' => ['resolver' => 'bombs'],
             'do'   => [['set', 'status', '=', 'still_ran']]],
        ]);

        $engine = new StepEngine($registry, $calc);
        $result = $engine->run('leave_request', 'after_save', ['id' => 1, 'status' => 'draft']);

        $this->assertSame('still_ran', $result['dto']['status']);
        $this->assertTrue($result['changed']);
    }

    public function testAuditLogRecordsMatchedSteps(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $registry->register('leave_request', 'after_save', [
            ['criteria' => [['status', 'eq', 'submitted']], 'do' => [['set', 'status', '=', 'pending']]],
        ]);

        $engine = new StepEngine($registry, new CalcRegistry());
        $engine->run('leave_request', 'after_save', ['id' => 1, 'status' => 'submitted']);

        $log = $engine->getAuditLog();
        $this->assertNotEmpty($log);
        $this->assertSame('leave_request', $log[0]['record_type']);
        $this->assertSame('after_save', $log[0]['event']);
        $this->assertSame('matched', $log[0]['detail']);
    }

    public function testNoStepsForEventIsNoOp(): void
    {
        $engine = new StepEngine(WorkflowRegistry::getInstance(), new CalcRegistry());
        $result = $engine->run('leave_request', 'after_save', ['id' => 1]);

        $this->assertSame(['id' => 1], $result['dto']);
        $this->assertFalse($result['changed']);
        $this->assertFalse($result['stopped']);
        $this->assertSame([], $engine->getAuditLog());
    }
}
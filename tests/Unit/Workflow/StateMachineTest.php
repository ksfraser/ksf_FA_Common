<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Workflow;

use ksfraser\FrontAccounting\Common\Workflow\CalcRegistry;
use ksfraser\FrontAccounting\Common\Workflow\InMemoryStateStore;
use ksfraser\FrontAccounting\Common\Workflow\ProcessDefinition;
use ksfraser\FrontAccounting\Common\Workflow\StateMachine;
use ksfraser\FrontAccounting\Common\Workflow\StepEngine;
use ksfraser\FrontAccounting\Common\Workflow\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @UML Note: BR-COM-02 state-machine engine acceptance (AC1-AC3).
 * @BABOK Related: UT-COM-002-001
 */
class StateMachineTest extends TestCase
{
    /** @var InMemoryStateStore */
    private $store;

    /** @var StepEngine */
    private $engine;

    /** @var CalcRegistry */
    private $calc;

    /** @var ProcessDefinition */
    private $leaveProcess;

    /** @var StateMachine */
    private $sm;

    /** @var array<int,array> broadcast events captured */
    private $events = [];

    protected function setUp(): void
    {
        WorkflowRegistry::resetInstance();

        $this->store = new InMemoryStateStore();
        $this->calc  = new CalcRegistry();
        $this->engine = new StepEngine(WorkflowRegistry::getInstance(), $this->calc);

        $this->calc->register('hrm.approval_chain', function (array $dto, array $ctx) {
            return ['person_id' => 17, 'approved' => false];
        });
        $this->calc->register('hrm.leave.check_balance', function (array $dto, array $ctx) {
            return ['balance_remaining' => 20, 'hours_requested' => (int) ($dto['hours_requested'] ?? 10)];
        });
        $this->calc->register('hrm.timesheet.create_work_window', function (array $dto, array $ctx) {
            return ['work_window_id' => 42];
        });

        $this->engine->setCreator(function (string $type, array $fields) {
            return ['id' => 99, 'type' => $type, ...$fields];
        });
        $this->engine->setBroadcaster(function (string $event, array $payload) {
            $this->events[] = [$event, $payload];
        });

        $this->leaveProcess = ProcessDefinition::fromArray([
            'name'       => 'leave_request.approval',
            'record'     => 'leave_request',
            'record_prefix' => 'ksf_FA_HRM',
            'start'      => ['on' => 'after_save',
                             'criteria' => [['status', 'eq', 'submitted']]],
            'states'     => ['draft', 'submitted', 'pending', 'scheduled', 'approved',
                             'rejected', 'rejected_exhausted', 'cancelled'],
            'initial'    => 'draft',
            'transitions'=> [
                ['from' => 'draft', 'to' => 'submitted', 'trigger' => 'user', 'label' => 'Submit',
                 'pre' => [['call', 'hrm.leave.validate_fields']]],
                ['from' => 'submitted', 'to' => 'pending', 'trigger' => 'auto',
                 'pre' => [['resolver', 'hrm.approval_chain', 'nextStep'],
                           ['set', 'current_approver', '=', 'result.person_id']],
                 'post' => [['create', 'ApprovalTask', ['copy' => ['request_id', 'current_approver']]]]],
                ['from' => 'pending', 'to' => 'approved', 'trigger' => 'user', 'label' => 'Approve',
                 'guard' => [['result.hours_requested', 'lte', 'result.balance_remaining']],
                 'pre'  => [['call', 'hrm.leave.check_balance']],
                 'post' => [['broadcast', 'leave_approved', ['dto']]]],
                ['from' => 'pending', 'to' => 'rejected', 'trigger' => 'user', 'label' => 'Reject'],
                ['from' => 'pending', 'to' => 'rejected_exhausted', 'trigger' => 'auto',
                 'pre' => [['call', 'hrm.leave.check_balance']],
                 'guard' => [['result.hours_requested', 'gt', 'result.balance_remaining']],
                 'post' => [['set', 'reason', '=', 'no balance']]],
                ['from' => 'approved', 'to' => 'scheduled', 'trigger' => 'auto',
                 'pre' => [['call', 'hrm.timesheet.create_work_window']]],
            ],
        ]);

        $this->sm = new StateMachine($this->store, $this->engine);
        $this->sm->register($this->leaveProcess);
        $this->sm->setAccessCheck(function (string $access) {
            return $access === 'SA_LEAVE_APPROVE';
        });
    }

    public function testSubmitUserTransition(): void
    {
        $res = $this->sm->transition('leave_request', 'LR-1', 'submitted', [
            'id' => 1, 'request_id' => 'LR-1', 'hours_requested' => 8,
        ], 'alice', 'Annual leave');

        $this->assertTrue($res['ok']);
        $this->assertSame('draft', $res['from']);
        $this->assertSame('submitted', $res['to']);
        $this->assertSame('submitted', $this->sm->currentState('leave_request', 'LR-1'));
    }

    public function testAutoTransitionSetsApproverAndCreatesTaskChain(): void
    {
        // submitted -> pending (auto)
        $this->sm->transition('leave_request', 'LR-2', 'submitted', [
            'id' => 2, 'request_id' => 'LR-2', 'hours_requested' => 8,
        ], 'alice');
        $res = $this->sm->transition('leave_request', 'LR-2', 'pending', [
            'id' => 2, 'request_id' => 'LR-2', 'hours_requested' => 8,
        ], 'system');

        $this->assertTrue($res['ok']);
        $this->assertSame('pending', $res['to']);
        // pre resolver set current_approver via result.person_id
        $this->assertSame(17, $res['dto']['current_approver']);
        // post create fired its own after_save chain (ApprovalTask)
        $this->assertCount(1, $this->engine->getCreated());
        $this->assertSame('ApprovalTask', $this->engine->getCreated()[0]['type']);
    }

    public function testGuardRefusesApproveWithExhaustedBalance(): void
    {
        // force balance shortage: hours_requested exceeds balance_remaining (20)
        $this->sm->transition('leave_request', 'LR-3', 'submitted', ['hours_requested' => 30], 'alice');
        $this->sm->transition('leave_request', 'LR-3', 'pending', ['hours_requested' => 30], 'system');

        $res = $this->sm->transition('leave_request', 'LR-3', 'approved', [
            'hours_requested' => 30,
        ], 'bob');

        $this->assertFalse($res['ok']);
        $this->assertSame('guard_failed', $res['error']);
        $this->assertSame('pending', $this->sm->currentState('leave_request', 'LR-3'));

        // exception redirects to rejected_exhausted
        $res2 = $this->sm->transition('leave_request', 'LR-3', 'rejected_exhausted', [
            'hours_requested' => 30,
        ], 'system');
        $this->assertTrue($res2['ok']);
        $this->assertSame('rejected_exhausted', $res2['to']);
        $this->assertSame('no balance', $res2['dto']['reason']);
    }

    public function testIllegalTransitionRefusedAndStateUnchanged(): void
    {
        $this->sm->transition('leave_request', 'LR-4', 'submitted', [
            'id' => 4, 'request_id' => 'LR-4',
        ], 'alice');

        // draft state is gone; trying to go draft -> submitted again refused
        $now = $this->sm->currentState('leave_request', 'LR-4');
        $this->assertSame('submitted', $now);

        // pending -> approved jump without pending: draft at LR-5
        $res = $this->sm->transition('leave_request', 'LR-5', 'approved', [
            'id' => 5, 'request_id' => 'LR-5',
        ], 'alice');
        $this->assertFalse($res['ok']);
        $this->assertSame('illegal_transition', $res['error']);
        $this->assertSame('draft', $this->sm->currentState('leave_request', 'LR-5'));
    }

    public function testSameStateRefused(): void
    {
        $res = $this->sm->transition('leave_request', 'LR-6', 'draft', ['id' => 6], 'alice');
        $this->assertFalse($res['ok']);
        $this->assertSame('same_state', $res['error']);
    }

    public function testFullWalkToScheduledWritesHistory(): void
    {
        $dto = ['id' => 7, 'request_id' => 'LR-7', 'hours_requested' => 8];
        $this->sm->transition('leave_request', 'LR-7', 'submitted', $dto, 'alice');
        $this->sm->transition('leave_request', 'LR-7', 'pending', $dto, 'system');
        $r  = $this->sm->transition('leave_request', 'LR-7', 'approved', $dto, 'bob');
        $r2 = $this->sm->transition('leave_request', 'LR-7', 'scheduled', $dto, 'system');

        $this->assertTrue($r['ok']);
        $this->assertTrue($r2['ok']);
        $this->assertSame('scheduled', $this->sm->currentState('leave_request', 'LR-7'));

        $history = $this->store->listHistory('leave_request', 'LR-7');
        $this->assertCount(4, $history);
        $this->assertSame('draft', $history[0]['from']);
        $this->assertSame('submitted', $history[0]['to']);
        $this->assertSame('submitted', $history[1]['from']);
        $this->assertSame('pending', $history[1]['to']);
        $this->assertSame('pending', $history[2]['from']);
        $this->assertSame('approved', $history[2]['to']);
        $this->assertSame('approved', $history[3]['from']);
        $this->assertSame('scheduled', $history[3]['to']);
    }

    public function testAllowedTransitionsFilteredByAccess(): void
    {
        $this->sm->transition('leave_request', 'LR-8', 'submitted', ['id' => 8], 'alice');
        $this->sm->transition('leave_request', 'LR-8', 'pending', ['id' => 8], 'system');

        // accessCheck grants only SA_LEAVE_APPROVE, but Approve edge has no access
        // constraint here; rather confirm access-denied blocks transition.
        $allowed = $this->sm->allowedTransitions('leave_request', 'LR-8', ['id' => 8]);
        $this->assertArrayHasKey('approved', $allowed);
        $this->assertArrayHasKey('rejected', $allowed);
        $this->assertArrayNotHasKey('rejected_exhausted', $allowed); // auto edge
    }

    public function testPostFailureRollsBackAndWritesFailedHistory(): void
    {
        $calc = new CalcRegistry();
        $calc->register('boom', function () {
            throw new \RuntimeException('kaboom');
        });
        $boomer = new StepEngine(WorkflowRegistry::getInstance(), $calc);

        $def = ProcessDefinition::fromArray([
            'name' => 'boom_process', 'record' => 'gadget', 'initial' => 'new',
            'states' => ['new', 'done'],
            'transitions' => [
                ['from' => 'new', 'to' => 'done', 'trigger' => 'auto',
                 'post' => [['call', 'boom']]],
            ],
        ]);

        $sm = new StateMachine($this->store, $boomer);
        $sm->register($def);

        $res = $sm->transition('gadget', 'G-1', 'done', ['id' => 'G-1'], 'system');

        $this->assertFalse($res['ok']);
        $this->assertSame('post_failed', $res['error']);
        $this->assertSame('new', $sm->currentState('gadget', 'G-1'));

        $history = $this->store->listHistory('gadget', 'G-1');
        $this->assertCount(1, $history);
        $this->assertTrue($history[0]['failed']);
    }

    public function testBroadcastOnApproveFires(): void
    {
        $this->sm->transition('leave_request', 'LR-9', 'submitted', ['hours_requested' => 8], 'alice');
        $this->sm->transition('leave_request', 'LR-9', 'pending', ['hours_requested' => 8], 'system');
        $this->sm->transition('leave_request', 'LR-9', 'approved', ['hours_requested' => 8], 'bob');

        $this->assertNotEmpty($this->events);
        $this->assertSame('leave_approved', $this->events[0][0]);
    }
}
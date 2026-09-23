<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Workflow;

use ksfraser\FrontAccounting\Common\Workflow\ProcessDefinition;
use PHPUnit\Framework\TestCase;

class ProcessDefinitionTest extends TestCase
{
    private function leaveTransitions(): array
    {
        return [
            ['from' => 'draft', 'to' => 'submitted', 'trigger' => 'user', 'label' => 'Submit',
             'pre' => [['call', 'hrm.leave.validate_fields']]],
            ['from' => 'submitted', 'to' => 'pending', 'trigger' => 'auto',
             'pre' => [['resolver', 'hrm.approval_chain', 'nextStep'],
                       ['set', 'current_approver', '=', 'result.person_id']],
             'post' => [['create', 'ApprovalTask', ['copy' => ['request_id', 'current_approver']]]]],
            ['from' => 'pending', 'to' => 'approved', 'trigger' => 'user', 'label' => 'Approve',
             'guard' => [['result.balance_remaining', 'gte', 'result.hours_requested']],
             'pre'  => [['call', 'hrm.leave.check_balance']],
             'post' => [['broadcast', 'leave_approved', ['dto']]]],
            ['from' => 'pending', 'to' => 'rejected', 'trigger' => 'user', 'label' => 'Reject',
             'prompt' => [['reason', '=', 'required']]],
            ['from' => 'pending', 'to' => 'rejected_exhausted', 'trigger' => 'auto',
             'guard' => [['result.balance_remaining', 'lt', 'result.hours_requested']],
             'post' => [['set', 'reason', '=', 'no balance']]],
            ['from' => 'approved', 'to' => 'scheduled', 'trigger' => 'auto',
             'pre' => [['call', 'hrm.timesheet.create_work_window']]],
        ];
    }

    public function testValidLeaveProcess(): void
    {
        $def = ProcessDefinition::fromArray([
            'name'       => 'leave_request.approval',
            'record'     => 'leave_request',
            'record_prefix' => 'ksf_FA_HRM',
            'start'      => ['on' => 'after_save',
                             'criteria' => [['status', 'eq', 'submitted']]],
            'states'     => ['draft', 'submitted', 'pending', 'scheduled', 'approved',
                             'rejected', 'rejected_exhausted', 'cancelled'],
            'initial'    => 'draft',
            'version'    => 3,
            'transitions'=> $this->leaveTransitions(),
        ]);

        $this->assertSame('leave_request', $def->getRecord());
        $this->assertSame('draft', $def->getInitial());
        $this->assertSame(3, $def->getVersion());
        $this->assertTrue($def->hasTransition('draft', 'submitted'));
        $this->assertFalse($def->hasTransition('draft', 'approved'));
        $this->assertCount(2, $def->transitionsFrom('pending', 'user'));
        $this->assertCount(1, $def->transitionsFrom('pending', 'auto'));
    }

    public function testSelfLoopRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProcessDefinition('p', 'rec', 'a', ['a', 'b'], [
            ['from' => 'a', 'to' => 'a'],
        ]);
    }

    public function testUnknownTargetRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProcessDefinition('p', 'rec', 'a', ['a', 'b'], [
            ['from' => 'a', 'to' => 'zzz'],
        ]);
    }

    public function testInitialNotInStatesRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProcessDefinition('p', 'rec', 'a', ['b', 'c'], []);
    }

    public function testDuplicateStatesRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProcessDefinition('p', 'rec', 'a', ['a', 'a'], []);
    }

    public function testDuplicateEdgeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProcessDefinition('p', 'rec', 'a', ['a', 'b'], [
            ['from' => 'a', 'to' => 'b'],
            ['from' => 'a', 'to' => 'b'],
        ]);
    }

    public function testBadGuardOperatorRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProcessDefinition('p', 'rec', 'a', ['a', 'b'], [
            ['from' => 'a', 'to' => 'b', 'guard' => [['x', 'banana', 1]]],
        ]);
    }

    public function testUnknownVerbRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProcessDefinition('p', 'rec', 'a', ['a', 'b'], [
            ['from' => 'a', 'to' => 'b', 'pre' => [['teleport']]],
        ]);
    }

    public function testCycleRejectedAtDesignTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cycle');
        new ProcessDefinition('p', 'rec', 'a', ['a', 'b', 'c'], [
            ['from' => 'a', 'to' => 'b'],
            ['from' => 'b', 'to' => 'c'],
            ['from' => 'c', 'to' => 'a'],
        ]);
    }

    public function testAcyclicGraphAccepted(): void
    {
        $def = ProcessDefinition::fromArray([
            'name' => 'g', 'record' => 'rec', 'initial' => 'a',
            'states' => ['a', 'b', 'c', 'd'],
            'transitions' => [
                ['from' => 'a', 'to' => 'b'],
                ['from' => 'a', 'to' => 'c'],
                ['from' => 'b', 'to' => 'd'],
                ['from' => 'c', 'to' => 'd'],
            ],
        ]);
        $this->assertCount(4, $def->getStates());
    }

    public function testJsonRoundTrip(): void
    {
        $orig = ProcessDefinition::fromArray([
            'name' => 'leave_request.approval',
            'record' => 'leave_request',
            'record_prefix' => 'ksf_FA_HRM',
            'states' => ['draft', 'submitted', 'pending', 'scheduled', 'approved',
                         'rejected', 'rejected_exhausted', 'cancelled'],
            'initial' => 'draft',
            'version' => 2,
            'history' => false,
            'transitions' => $this->leaveTransitions(),
        ]);

        $rt = ProcessDefinition::fromJson($orig->toJson());
        $this->assertSame($orig->toArray(), $rt->toArray());
    }
}
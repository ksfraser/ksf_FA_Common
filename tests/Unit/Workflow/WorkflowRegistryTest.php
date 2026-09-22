<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Workflow;

use ksfraser\FrontAccounting\Common\Workflow\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

class WorkflowRegistryTest extends TestCase
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

    public function testSingleton(): void
    {
        $this->assertSame(WorkflowRegistry::getInstance(), WorkflowRegistry::getInstance());
    }

    public function testRegisterAndGetSteps(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $step     = ['criteria' => [['status', 'eq', 'submitted']], 'do' => [['set', 'status', '=', 'pending']]];

        $registry->register('leave_request', 'after_save', [$step]);

        $this->assertSame([$step], $registry->getSteps('leave_request', 'after_save'));
    }

    public function testRegisterAppendsInOrder(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $registry->register('leave_request', 'after_save', [['do' => [['set', 'a', '=', 1]]]]);
        $registry->register('leave_request', 'after_save', [['do' => [['set', 'b', '=', 2]]]]);

        $steps = $registry->getSteps('leave_request', 'after_save');
        $this->assertCount(2, $steps);
        $this->assertSame('a', $steps[0]['do'][0][1]);
        $this->assertSame('b', $steps[1]['do'][0][1]);
    }

    public function testUnknownEventReturnsEmpty(): void
    {
        $this->assertSame([], WorkflowRegistry::getInstance()->getSteps('nope', 'after_save'));
    }

    public function testGetRecordTypes(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $registry->register('leave_request', 'after_save', []);
        $registry->register('order', 'after_save', []);
        $registry->register('leave_request', 'before_delete', []);

        $types = $registry->getRecordTypes();
        $this->assertContains('leave_request', $types);
        $this->assertContains('order', $types);
        $this->assertCount(2, $types);
    }

    public function testMaxDepthDefaultsToFive(): void
    {
        $this->assertSame(5, WorkflowRegistry::getInstance()->getMaxDepth());
        WorkflowRegistry::getInstance()->setMaxDepth(3);
        $this->assertSame(3, WorkflowRegistry::getInstance()->getMaxDepth());
    }

    public function testClear(): void
    {
        $registry = WorkflowRegistry::getInstance();
        $registry->register('leave_request', 'after_save', [['do' => []]]);
        $registry->clear();
        $this->assertSame([], $registry->getSteps('leave_request', 'after_save'));
    }
}
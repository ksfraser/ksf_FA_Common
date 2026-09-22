<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Workflow;

use ksfraser\FrontAccounting\Common\Workflow\CriteriaEvaluator;
use PHPUnit\Framework\TestCase;

class CriteriaEvaluatorTest extends TestCase
{
    public function testEmptyCriteriaAlwaysTrue(): void
    {
        $this->assertTrue(CriteriaEvaluator::all([], ['any' => 1]));
    }

    public function testEqMatch(): void
    {
        $criteria = [['status', 'eq', 'submitted']];
        $this->assertTrue(CriteriaEvaluator::all($criteria, ['status' => 'submitted']));
        $this->assertFalse(CriteriaEvaluator::all($criteria, ['status' => 'pending']));
    }

    public function testNeGtGteLtLte(): void
    {
        $this->assertTrue(CriteriaEvaluator::all([['days', 'gt', 3]], ['days' => 5]));
        $this->assertFalse(CriteriaEvaluator::all([['days', 'gt', 3]], ['days' => 2]));

        $this->assertTrue(CriteriaEvaluator::all([['days', 'gte', 3]], ['days' => 3]));
        $this->assertFalse(CriteriaEvaluator::all([['days', 'gte', 3]], ['days' => 2]));

        $this->assertTrue(CriteriaEvaluator::all([['days', 'lt', 3]], ['days' => 2]));
        $this->assertTrue(CriteriaEvaluator::all([['days', 'lte', 3]], ['days' => 3]));

        $this->assertTrue(CriteriaEvaluator::all([['days', 'ne', 3]], ['days' => 4]));
        $this->assertFalse(CriteriaEvaluator::all([['days', 'ne', 3]], ['days' => 3]));
    }

    public function testInAndNin(): void
    {
        $this->assertTrue(CriteriaEvaluator::all([['status', 'in', ['a', 'b']]], ['status' => 'b']));
        $this->assertFalse(CriteriaEvaluator::all([['status', 'in', ['a', 'b']]], ['status' => 'c']));

        $this->assertTrue(CriteriaEvaluator::all([['status', 'nin', ['a', 'b']]], ['status' => 'c']));
    }

    public function testContains(): void
    {
        $this->assertTrue(CriteriaEvaluator::all([['name', 'contains', 'Ada']], ['name' => 'Ada Lovelace']));
        $this->assertFalse(CriteriaEvaluator::all([['name', 'contains', 'Grace']], ['name' => 'Ada Lovelace']));

        $this->assertTrue(CriteriaEvaluator::all([['name', 'not_contains', 'Grace']], ['name' => 'Ada Lovelace']));
    }

    public function testNullAndIsset(): void
    {
        $this->assertTrue(CriteriaEvaluator::all([['approver_id', 'is_null']], ['approver_id' => null]));
        $this->assertTrue(CriteriaEvaluator::all([['approver_id', 'not_null']], ['approver_id' => 4]));
        $this->assertTrue(CriteriaEvaluator::all([['approver_id', 'isset']], ['approver_id' => 4]));
        $this->assertFalse(CriteriaEvaluator::all([['approver_id', 'isset']], ['approver_id' => null]));
    }

    public function testAllCriteriaAnded(): void
    {
        $criteria = [
            ['status', 'eq', 'submitted'],
            ['days', 'lte', 5],
        ];
        $this->assertTrue(CriteriaEvaluator::all($criteria, ['status' => 'submitted', 'days' => 3]));
        $this->assertFalse(CriteriaEvaluator::all($criteria, ['status' => 'submitted', 'days' => 9]));
    }

    public function testResultReferenceInValue(): void
    {
        $criteria  = [['hours', 'gte', 'result.min_hours']];
        $context   = ['result' => ['min_hours' => 8]];
        $this->assertTrue(CriteriaEvaluator::all($criteria, ['hours' => 9], $context));
        $this->assertFalse(CriteriaEvaluator::all($criteria, ['hours' => 7], $context));
    }

    public function testResultReferenceInField(): void
    {
        $criteria = [['result.threshold', 'eq', 10]];
        $context  = ['result' => ['threshold' => 10]];
        $this->assertTrue(CriteriaEvaluator::all($criteria, [], $context));
    }

    public function testUnknownOpDefaultsTrue(): void
    {
        $this->assertTrue(CriteriaEvaluator::all([['x', 'bogus_operator', 1]], ['x' => 1]));
    }
}
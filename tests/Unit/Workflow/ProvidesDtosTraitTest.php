<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Workflow;

use ksfraser\FrontAccounting\Common\Workflow\ProvidesDtosTrait;
use PHPUnit\Framework\TestCase;

class ProvidesDtosTraitTest extends TestCase
{
    public function testUnregisteredTypeYieldsNull(): void
    {
        $hooks = new ProvidesDtosStub();
        $payload = ['type' => 'leave_request', 'id' => 1];
        $hooks->respondGetDto($payload);

        $this->assertNull($payload['dto']);
        $this->assertSame([], $payload['schema']);
    }

    public function testGetDtoPullsRegisteredBuilder(): void
    {
        $hooks = new ProvidesDtosStub();
        $hooks->registerStubDto('leave_request', function ($id) {
            return [['id' => $id, 'status' => 'submitted'], ['fields' => ['id' => 'int']]];
        });

        $payload = ['type' => 'leave_request', 'id' => 9];
        $hooks->respondGetDto($payload);

        $this->assertSame(['id' => 9, 'status' => 'submitted'], $payload['dto']);
        $this->assertSame(['fields' => ['id' => 'int']], $payload['schema']);
    }

    public function testGetDtoBuilderReturningNullDto(): void
    {
        $hooks = new ProvidesDtosStub();
        $hooks->registerStubDto('leave_request', function ($id) {
            return [null, ['fields' => []]];
        });

        $payload = ['type' => 'leave_request', 'id' => 404];
        $hooks->respondGetDto($payload);

        $this->assertNull($payload['dto']);
        $this->assertSame(['fields' => []], $payload['schema']);
    }

    public function testUnregisteredListTypeYieldsEmpty(): void
    {
        $hooks   = new ProvidesDtosStub();
        $payload = ['type' => 'nope', 'criteria' => [], 'limit' => 10];
        $hooks->respondGetDtoList($payload);

        $this->assertSame([], $payload['dtos']);
        $this->assertSame([], $payload['schema']);
    }

    public function testGetDtoListPullsListBuilder(): void
    {
        $hooks = new ProvidesDtosStub();
        $hooks->registerStubDto('leave_request', function ($id) {
            return [null, []];
        }, function (array $criteria, int $limit) {
            return [[
                ['id' => 1, 'status' => 'submitted'],
                ['id' => 2, 'status' => 'submitted'],
            ], ['fields' => ['id' => 'int']]];
        });

        $payload = ['type' => 'leave_request', 'criteria' => ['status' => 'submitted'], 'limit' => 25];
        $hooks->respondGetDtoList($payload);

        $this->assertCount(2, $payload['dtos']);
        $this->assertSame('submitted', $payload['dtos'][1]['status']);
        $this->assertSame(['fields' => ['id' => 'int']], $payload['schema']);
    }

    public function testEmptyTypePayloadIsIgnored(): void
    {
        $hooks   = new ProvidesDtosStub();
        $payload = ['id' => 1];
        $hooks->respondGetDto($payload);

        $this->assertNull($payload['dto']);
    }
}

class ProvidesDtosStub
{
    use ProvidesDtosTrait;

    public function registerStubDto(string $type, callable $builder, ?callable $list = null): void
    {
        $this->registerDtoType($type, $builder, $list);
    }
}
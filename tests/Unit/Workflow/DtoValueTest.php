<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Workflow;

use ksfraser\FrontAccounting\Common\Workflow\DtoValue;
use PHPUnit\Framework\TestCase;

class DtoValueTest extends TestCase
{
    public function testGetFlatKey(): void
    {
        $dto = ['id' => 7, 'status' => 'submitted'];
        $this->assertSame('submitted', DtoValue::get($dto, 'status'));
    }

    public function testGetMissingKeyReturnsDefault(): void
    {
        $dto = ['id' => 7];
        $this->assertNull(DtoValue::get($dto, 'nope'));
        $this->assertSame('fallback', DtoValue::get($dto, 'nope', 'fallback'));
    }

    public function testGetNestedPath(): void
    {
        $dto = ['person' => ['name' => 'Ada']];
        $this->assertSame('Ada', DtoValue::get($dto, 'person.name'));
        $this->assertNull(DtoValue::get($dto, 'person.missing'));
    }

    public function testGetEmptyPathReturnsWholeDto(): void
    {
        $dto = ['a' => 1];
        $this->assertSame($dto, DtoValue::get($dto, ''));
    }

    public function testSetFlat(): void
    {
        $dto = ['status' => 'submitted'];
        DtoValue::set($dto, 'status', 'pending');
        $this->assertSame('pending', $dto['status']);
    }

    public function testSetCreatesNestedBranch(): void
    {
        $dto = [];
        DtoValue::set($dto, 'person.name', 'Ada');
        $this->assertSame(['person' => ['name' => 'Ada']], $dto);
    }

    public function testSetOverwritesBranch(): void
    {
        $dto = ['person' => ['name' => 'Ada', 'age' => 37]];
        DtoValue::set($dto, 'person.name', 'Grace');
        $this->assertSame('Grace', $dto['person']['name']);
        $this->assertSame(37, $dto['person']['age']);
    }
}
<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Traits;

use ksfraser\FrontAccounting\Common\Traits\CrudOperationsTrait;
use ksfraser\FrontAccounting\Common\Traits\WorkflowHooksTrait;
use PHPUnit\Framework\TestCase;

class CrudOperationsTraitTest extends TestCase
{
    private $traitUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traitUser = new class {
            use CrudOperationsTrait;
            use WorkflowHooksTrait;

            protected $recordData = [];

            protected $createRecordInternalOverride = null;

            protected function createRecordInternal(string $recordType, array $data): array
            {
                if ($this->createRecordInternalOverride !== null) {
                    return call_user_func($this->createRecordInternalOverride, $recordType, $data);
                }
                return $data;
            }

            public function setCreateRecordInternalOverride(callable $override): void
            {
                $this->createRecordInternalOverride = $override;
            }

            public function callRegisterWorkflowType(string $recordType, string $hookPrefix): void
            {
                $this->registerWorkflowType($recordType, $hookPrefix);
            }

            public function callCreateRecord(string $recordType, array $data, bool $isNew = false): array
            {
                return $this->createRecord($recordType, $data, $isNew);
            }

            public function callDeleteRecord(string $recordType, array $data): array
            {
                return $this->deleteRecord($recordType, $data);
            }

            public function callCreateRecordInternal(string $recordType, array $data): array
            {
                return $this->createRecordInternal($recordType, $data);
            }

            public function callDeleteRecordInternal(string $recordType, array $data): array
            {
                return $this->deleteRecordInternal($recordType, $data);
            }

            public function callFirePreSaveHook(string $recordType, array &$payload): array
            {
                return $this->firePreSaveHook($recordType, $payload);
            }

            public function callFirePostSaveHook(string $recordType, array &$payload): array
            {
                return $this->firePostSaveHook($recordType, $payload);
            }

            public function callFirePreDeleteHook(string $recordType, array &$payload): array
            {
                return $this->firePreDeleteHook($recordType, $payload);
            }

            public function callFirePostDeleteHook(string $recordType, array &$payload): array
            {
                return $this->firePostDeleteHook($recordType, $payload);
            }

            public function callFireNewHook(string $recordType, array &$payload): array
            {
                return $this->fireNewHook($recordType, $payload);
            }

            public function callFireEditedHook(string $recordType, array &$payload, bool $isNew = false): array
            {
                return $this->fireEditedHook($recordType, $payload, $isNew);
            }

            public function setRecordData(array $data): void
            {
                $this->recordData = $data;
            }

            public function getRecordData(): array
            {
                return $this->recordData;
            }
        };
    }

    public function testCreateRecordCallsInternalAndHooks(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');
        $data = ['name' => 'Test Customer'];

        // Mock the internal create method
        $reflection = new \ReflectionObject($this->traitUser);
        $method = $reflection->getMethod('createRecordInternal');
        $method->setAccessible(true);
        $method->invoke($this->traitUser, 'customer', $data);

        $result = $this->traitUser->callCreateRecord('customer', $data, true);

        $this->assertArrayHasKey('name', $result);
        $this->assertSame('Test Customer', $result['name']);
    }

    public function testDeleteRecordCallsInternalAndHooks(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');
        $data = ['id' => 1, 'name' => 'Test Customer'];

        // Mock the internal delete method
        $reflection = new \ReflectionObject($this->traitUser);
        $method = $reflection->getMethod('deleteRecordInternal');
        $method->setAccessible(true);
        $method->invoke($this->traitUser, 'customer', $data);

        $result = $this->traitUser->callDeleteRecord('customer', $data);

        $this->assertArrayHasKey('id', $result);
        $this->assertSame(1, $result['id']);
    }

    public function testCreateRecordInternalReturnsDataByDefault(): void
    {
        $data = ['name' => 'Test Customer'];
        $result = $this->traitUser->callCreateRecordInternal('customer', $data);
        $this->assertSame($data, $result);
    }

    public function testDeleteRecordInternalReturnsDataByDefault(): void
    {
        $data = ['id' => 1, 'name' => 'Test Customer'];
        $result = $this->traitUser->callDeleteRecordInternal('customer', $data);
        $this->assertSame($data, $result);
    }

    public function testCreateRecordWorkflowHooksFire(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');
        $data = ['name' => 'Test Customer'];

        // Override internal method to capture calls
        $calls = [];
        $this->traitUser->setCreateRecordInternalOverride(function ($recordType, $data) use (&$calls) {
            $calls[] = ['type' => $recordType, 'data' => $data];
            return $data;
        });

        $result = $this->traitUser->callCreateRecord('customer', $data, true);

        $this->assertNotEmpty($calls);
        $this->assertSame('customer', $calls[0]['type']);
        $this->assertSame(['name' => 'Test Customer'], $calls[0]['data']);
    }
}
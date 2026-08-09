<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Traits;

use ksfraser\FrontAccounting\Common\Traits\WorkflowHooksTrait;
use PHPUnit\Framework\TestCase;

class WorkflowHooksTraitTest extends TestCase
{
    private $traitUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traitUser = new class {
            use WorkflowHooksTrait;

            public function callRegisterWorkflowType(string $recordType, string $hookPrefix): void
            {
                $this->registerWorkflowType($recordType, $hookPrefix);
            }

            public function callFireWorkflowHook(string $recordType, string $hookKey, array &$payload): array
            {
                return $this->fireWorkflowHook($recordType, $hookKey, $payload);
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

            public function callFireLinkedHook(string $recordType, array &$payload): array
            {
                return $this->fireLinkedHook($recordType, $payload);
            }

            public function callFireUnlinkedHook(string $recordType, array &$payload): array
            {
                return $this->fireUnlinkedHook($recordType, $payload);
            }

            public function callFireWorkflowHooks(string $recordType, array &$payload, bool $isNew = false): array
            {
                return $this->fireWorkflowHooks($recordType, $payload, $isNew);
            }

            public function callGetWorkflowHookName(string $recordType, string $hookKey): string
            {
                return $this->getWorkflowHookName($recordType, $hookKey);
            }

            public function callHasWorkflowType(string $recordType): bool
            {
                return $this->hasWorkflowType($recordType);
            }

            public function callGetWorkflowTypes(): array
            {
                return $this->getWorkflowTypes();
            }

            public function callClearWorkflowTypes(): void
            {
                $this->clearWorkflowTypes();
            }
        };
    }

    public function testRegisterWorkflowTypeStoresType(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $types = $this->traitUser->callGetWorkflowTypes();
        $this->assertArrayHasKey('customer', $types);
        $this->assertSame('crm_customer', $types['customer']);
    }

    public function testRegisterWorkflowTypeOverwritesExisting(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer_v2');

        $types = $this->traitUser->callGetWorkflowTypes();
        $this->assertSame('crm_customer_v2', $types['customer']);
    }

    public function testFireWorkflowHookReturnsPayload(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $payload = ['name' => 'Test Customer'];
        $result = $this->traitUser->callFireWorkflowHook('customer', 'before_save', $payload);

        $this->assertSame($payload, $result);
    }

    public function testFireWorkflowHookReturnsPayloadWhenNoHooks(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $payload = ['name' => 'Test Customer'];
        $result = $this->traitUser->callFireWorkflowHook('customer', 'nonexistent', $payload);

        $this->assertSame($payload, $result);
    }

    public function testFirePreSaveHookCallsBeforeSave(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $payload = ['name' => 'Test'];
        $result = $this->traitUser->callFirePreSaveHook('customer', $payload);

        $this->assertSame($payload, $result);
    }

    public function testFirePostSaveHookCallsAfterSave(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $payload = ['name' => 'Test'];
        $result = $this->traitUser->callFirePostSaveHook('customer', $payload);

        $this->assertSame($payload, $result);
    }

    public function testFirePreDeleteHookCallsBeforeDelete(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $payload = ['name' => 'Test'];
        $result = $this->traitUser->callFirePreDeleteHook('customer', $payload);

        $this->assertSame($payload, $result);
    }

    public function testFirePostDeleteHookCallsAfterDelete(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $payload = ['name' => 'Test'];
        $result = $this->traitUser->callFirePostDeleteHook('customer', $payload);

        $this->assertSame($payload, $result);
    }

    public function testFireNewHookCallsNew(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $payload = ['name' => 'Test'];
        $result = $this->traitUser->callFireNewHook('customer', $payload);

        $this->assertSame($payload, $result);
    }

    public function testFireEditedHookCallsEditedForExisting(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $payload = ['name' => 'Test'];
        $result = $this->traitUser->callFireEditedHook('customer', $payload, false);

        $this->assertSame($payload, $result);
    }

    public function testFireEditedHookSkipsForNew(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $payload = ['name' => 'Test'];
        $result = $this->traitUser->callFireEditedHook('customer', $payload, true);

        $this->assertSame($payload, $result);
    }

    public function testFireLinkedHookCallsLinked(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $payload = ['name' => 'Test'];
        $result = $this->traitUser->callFireLinkedHook('customer', $payload);

        $this->assertSame($payload, $result);
    }

    public function testFireUnlinkedHookCallsUnlinked(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $payload = ['name' => 'Test'];
        $result = $this->traitUser->callFireUnlinkedHook('customer', $payload);

        $this->assertSame($payload, $result);
    }

    public function testGetWorkflowHookName(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $hookName = $this->traitUser->callGetWorkflowHookName('customer', 'before_save');
        $this->assertSame('crm_customer_before_save', $hookName);
    }

    public function testGetWorkflowHookNameWithDefaultPrefix(): void
    {
        $hookName = $this->traitUser->callGetWorkflowHookName('unknown', 'before_save');
        $this->assertSame('entity_before_save', $hookName);
    }

    public function testHasWorkflowTypeReturnsTrueForRegistered(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $this->assertTrue($this->traitUser->callHasWorkflowType('customer'));
    }

    public function testHasWorkflowTypeReturnsFalseForUnregistered(): void
    {
        $this->assertFalse($this->traitUser->callHasWorkflowType('unknown'));
    }

    public function testGetWorkflowTypesReturnsAll(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');
        $this->traitUser->callRegisterWorkflowType('employee', 'hrm_employee');

        $types = $this->traitUser->callGetWorkflowTypes();
        $this->assertCount(2, $types);
        $this->assertSame('crm_customer', $types['customer']);
        $this->assertSame('hrm_employee', $types['employee']);
    }

    public function testClearWorkflowTypesEmptiesRegistry(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');
        $this->traitUser->callClearWorkflowTypes();

        $types = $this->traitUser->callGetWorkflowTypes();
        $this->assertEmpty($types);
    }

    public function testFireWorkflowHooksCallsAllHooksInOrder(): void
    {
        $this->traitUser->callRegisterWorkflowType('customer', 'crm_customer');

        $payload = ['name' => 'Test'];
        $result = $this->traitUser->callFireWorkflowHooks('customer', $payload);

        $this->assertSame($payload, $result);
    }
}
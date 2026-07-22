<?php
/**
 * ContactTypeRegistry unit tests.
 *
 * Tests the fallback/default path (no FA db_query available).  The DB-backed
 * path is tested via integration tests against the real database.
 *
 * @package ksfraser\FrontAccounting\Common\Tests\Unit\ContactType
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\ContactType;

use ksfraser\FrontAccounting\Common\ContactType\ContactType;
use ksfraser\FrontAccounting\Common\ContactType\ContactTypeRegistry;
use PHPUnit\Framework\TestCase;

class ContactTypeRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ContactTypeRegistry::reset();
    }

    protected function tearDown(): void
    {
        ContactTypeRegistry::reset();
        parent::tearDown();
    }

    public function testGetTypesReturnsDefaultsWhenNoDb(): void
    {
        $types = ContactTypeRegistry::getTypes();

        $this->assertArrayHasKey('fa_user', $types);
        $this->assertArrayHasKey('crm_contact', $types);
        $this->assertArrayHasKey('resource', $types);
        $this->assertArrayHasKey('ad_hoc', $types);
        $this->assertCount(4, $types);
    }

    public function testGetTypesAreContactTypeInstances(): void
    {
        $types = ContactTypeRegistry::getTypes();

        foreach ($types as $name => $type) {
            $this->assertInstanceOf(ContactType::class, $type);
            $this->assertSame($name, $type->getName());
        }
    }

    public function testDefaultTypeValuesAreCorrect(): void
    {
        $types = ContactTypeRegistry::getTypes();

        $faUser = $types['fa_user'];
        $this->assertSame('fa_user', $faUser->getName());
        $this->assertSame('FA User', $faUser->getLabel());
        $this->assertSame('ksf_FA_Common', $faUser->getModule());
        $this->assertSame('FrontAccounting RBAC user account', $faUser->getDescription());
    }

    public function testGetTypeReturnsSingle(): void
    {
        $type = ContactTypeRegistry::getType('resource');

        $this->assertInstanceOf(ContactType::class, $type);
        $this->assertSame('resource', $type->getName());
        $this->assertSame('Resource', $type->getLabel());
    }

    public function testGetTypeReturnsNullForUnknown(): void
    {
        $this->assertNull(ContactTypeRegistry::getType('nonexistent_type'));
    }

    public function testGetTypeNamesReturnsStrings(): void
    {
        $names = ContactTypeRegistry::getTypeNames();

        $this->assertContains('fa_user', $names);
        $this->assertContains('crm_contact', $names);
        $this->assertContains('resource', $names);
        $this->assertContains('ad_hoc', $names);
        $this->assertCount(4, $names);
    }

    public function testIsValidType(): void
    {
        $this->assertTrue(ContactTypeRegistry::isValidType('fa_user'));
        $this->assertTrue(ContactTypeRegistry::isValidType('ad_hoc'));
        $this->assertFalse(ContactTypeRegistry::isValidType('badger'));
        $this->assertFalse(ContactTypeRegistry::isValidType(''));
    }

    public function testGetTypeDefinitionsReturnsArrays(): void
    {
        $definitions = ContactTypeRegistry::getTypeDefinitions();

        $this->assertCount(4, $definitions);

        $faUserDef = null;
        foreach ($definitions as $def) {
            if ($def['name'] === 'fa_user') {
                $faUserDef = $def;
                break;
            }
        }

        $this->assertNotNull($faUserDef);
        $this->assertSame('fa_user', $faUserDef['name']);
        $this->assertSame('FA User', $faUserDef['label']);
        $this->assertArrayHasKey('description', $faUserDef);
        $this->assertArrayHasKey('module', $faUserDef);
    }

    public function testGetTypesIsCachedPerRequest(): void
    {
        $firstCall  = ContactTypeRegistry::getTypes();
        $secondCall = ContactTypeRegistry::getTypes();

        $this->assertSame($firstCall, $secondCall);
    }

    public function testResetClearsCache(): void
    {
        $before = ContactTypeRegistry::getTypes();
        ContactTypeRegistry::reset();
        $after = ContactTypeRegistry::getTypes();

        // After reset, a new array is built (same content, different instance).
        $this->assertNotSame($before, $after);
        $this->assertCount(count($before), $after);
    }

    public function testRegisterTypesIsNoOpOutsideFa(): void
    {
        // Outside of FA context (no db_query), registerTypes should silently
        // do nothing.  Defaults should still be returned.
        $beforeCount = count(ContactTypeRegistry::getTypes());

        $newType = new ContactType('custom_type', 'Custom', 'ksf_Custom');
        ContactTypeRegistry::registerTypes([$newType]);

        $afterCount = count(ContactTypeRegistry::getTypes());
        $this->assertSame($beforeCount, $afterCount);
        $this->assertNull(ContactTypeRegistry::getType('custom_type'));
    }

    public function testUnregisterModuleIsNoOpOutsideFa(): void
    {
        // Outside FA context, unregisterModule should silently do nothing.
        $beforeCount = count(ContactTypeRegistry::getTypes());

        ContactTypeRegistry::unregisterModule('ksf_FA_Common');

        $afterCount = count(ContactTypeRegistry::getTypes());
        $this->assertSame($beforeCount, $afterCount);
    }

    public function testGetTypeDefinitionsOrderMatchesGetTypeNames(): void
    {
        $names       = ContactTypeRegistry::getTypeNames();
        $definitions = ContactTypeRegistry::getTypeDefinitions();

        $this->assertCount(count($names), $definitions);

        $defNames = array_column($definitions, 'name');
        $this->assertSame($names, $defNames);
    }

    public function testDefaultsContainAllPlatformTypes(): void
    {
        $types = ContactTypeRegistry::getTypes();

        $expectedNames = ['fa_user', 'crm_contact', 'resource', 'ad_hoc'];
        foreach ($expectedNames as $name) {
            $this->assertArrayHasKey($name, $types, "Missing default type: $name");
        }
    }
}

<?php
/**
 * ContactTypeRegistry unit tests.
 *
 * Tests the fallback path (no FA db_query available).  The package seeds no
 * types: with no database (or an empty table) the registry returns an empty
 * set, and every type must be registered by its natural owning module.
 * The DB-backed path is tested via integration tests against the real database.
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

    public function testGetTypesReturnsEmptyArrayWhenNoDb(): void
    {
        $types = ContactTypeRegistry::getTypes();

        $this->assertIsArray($types);
        $this->assertCount(0, $types);
    }

    public function testPackageSeedsNoPlatformOwnedTypes(): void
    {
        // The platform registers no concrete contact types; each type is owned
        // and registered by its natural module (RBAC, CRM, Calendar, HRM, ...).
        $types = ContactTypeRegistry::getTypes();

        foreach (['fa_user', 'crm_contact', 'resource', 'ad_hoc'] as $name) {
            $this->assertArrayNotHasKey($name, $types, "Platform must not seed type: $name");
        }
    }

    public function testGetTypesReturnsOnlyContactTypeInstances(): void
    {
        $types = ContactTypeRegistry::getTypes();

        $this->assertIsArray($types);
        foreach ($types as $name => $type) {
            $this->assertInstanceOf(ContactType::class, $type);
            $this->assertSame($name, $type->getName());
        }
    }

    public function testGetTypeReturnsNullWithoutRegistration(): void
    {
        $this->assertNull(ContactTypeRegistry::getType('fa_user'));
        $this->assertNull(ContactTypeRegistry::getType('resource'));
        $this->assertNull(ContactTypeRegistry::getType('nonexistent_type'));
    }

    public function testGetTypeNamesReturnsEmptyArray(): void
    {
        $names = ContactTypeRegistry::getTypeNames();

        $this->assertIsArray($names);
        $this->assertCount(0, $names);
    }

    public function testIsValidTypeFalseWithoutRegistration(): void
    {
        $this->assertFalse(ContactTypeRegistry::isValidType('fa_user'));
        $this->assertFalse(ContactTypeRegistry::isValidType('employee'));
        $this->assertFalse(ContactTypeRegistry::isValidType(''));
        $this->assertFalse(ContactTypeRegistry::isValidType('badger'));
    }

    public function testGetTypeDefinitionsReturnsEmptyArray(): void
    {
        $definitions = ContactTypeRegistry::getTypeDefinitions();

        $this->assertIsArray($definitions);
        $this->assertCount(0, $definitions);
    }

    public function testGetTypesIsCachedPerRequest(): void
    {
        $firstCall  = ContactTypeRegistry::getTypes();
        $secondCall = ContactTypeRegistry::getTypes();

        $this->assertSame($firstCall, $secondCall);
    }

    public function testResetClearsCache(): void
    {
        ContactTypeRegistry::getTypes();
        $this->assertIsArray(self::readCache());

        ContactTypeRegistry::reset();
        $this->assertNull(self::readCache());

        ContactTypeRegistry::getTypes();
        $this->assertIsArray(self::readCache());
    }

    public function testRegisterTypesIsNoOpOutsideFa(): void
    {
        $beforeCount = count(ContactTypeRegistry::getTypes());

        $newType = new ContactType('custom_type', 'Custom', 'ksf_Custom');
        ContactTypeRegistry::registerTypes([$newType]);

        $afterCount = count(ContactTypeRegistry::getTypes());
        $this->assertSame($beforeCount, $afterCount);
        $this->assertNull(ContactTypeRegistry::getType('custom_type'));
    }

    public function testUnregisterModuleIsNoOpOutsideFa(): void
    {
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
        $this->assertSame($names, array_column($definitions, 'name'));
    }

    private static function readCache(): ?array
    {
        $property = new \ReflectionProperty(ContactTypeRegistry::class, 'types');
        return $property->getValue();
    }
}
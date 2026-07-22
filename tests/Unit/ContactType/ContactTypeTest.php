<?php
/**
 * ContactType unit tests.
 *
 * @package ksfraser\FrontAccounting\Common\Tests\Unit\ContactType
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\ContactType;

use ksfraser\FrontAccounting\Common\ContactType\ContactType;
use PHPUnit\Framework\TestCase;

class ContactTypeTest extends TestCase
{
    public function testConstructWithRequiredFields(): void
    {
        $type = new ContactType('fa_user', 'FA User', 'ksf_FA_Common');

        $this->assertSame('fa_user', $type->getName());
        $this->assertSame('FA User', $type->getLabel());
        $this->assertSame('ksf_FA_Common', $type->getModule());
        $this->assertNull($type->getDescription());
    }

    public function testConstructWithOptionalDescription(): void
    {
        $type = new ContactType('employee', 'Employee', 'ksf_HRM', 'HRM employee record');

        $this->assertSame('employee', $type->getName());
        $this->assertSame('Employee', $type->getLabel());
        $this->assertSame('ksf_HRM', $type->getModule());
        $this->assertSame('HRM employee record', $type->getDescription());
    }

    public function testToArrayReturnsAllFields(): void
    {
        $type = new ContactType('resource', 'Resource', 'ksf_FA_Assets', 'Shared resource');

        $expected = [
            'name'        => 'resource',
            'label'       => 'Resource',
            'module'      => 'ksf_FA_Assets',
            'description' => 'Shared resource',
        ];

        $this->assertSame($expected, $type->toArray());
    }

    public function testToArrayWithNullDescription(): void
    {
        $type = new ContactType('ad_hoc', 'Ad-hoc', 'ksf_FA_Common');

        $expected = [
            'name'        => 'ad_hoc',
            'label'       => 'Ad-hoc',
            'module'      => 'ksf_FA_Common',
            'description' => null,
        ];

        $this->assertSame($expected, $type->toArray());
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new ContactType('test_type', 'Test Type', 'ksf_Test', 'A test description');
        $rebuilt  = ContactType::fromArray($original->toArray());

        $this->assertEquals($original, $rebuilt);
        $this->assertSame($original->getName(), $rebuilt->getName());
        $this->assertSame($original->getLabel(), $rebuilt->getLabel());
        $this->assertSame($original->getModule(), $rebuilt->getModule());
        $this->assertSame($original->getDescription(), $rebuilt->getDescription());
    }

    public function testFromArrayWithMissingOptionalFields(): void
    {
        $type = ContactType::fromArray([
            'name'   => 'minimal',
            'label'  => 'Minimal',
            'module' => 'ksf_Minimal',
        ]);

        $this->assertSame('minimal', $type->getName());
        $this->assertSame('Minimal', $type->getLabel());
        $this->assertSame('ksf_Minimal', $type->getModule());
        $this->assertNull($type->getDescription());
    }

    public function testFromArrayWithEmptyNameDefaults(): void
    {
        $type = ContactType::fromArray([
            'name'        => '',
            'label'       => 'Empty Name',
            'module'      => 'ksf_Test',
            'description' => 'Testing empty name',
        ]);

        $this->assertSame('', $type->getName());
        $this->assertSame('Empty Name', $type->getLabel());
    }
}

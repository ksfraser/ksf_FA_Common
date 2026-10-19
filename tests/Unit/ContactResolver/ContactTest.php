<?php
/**
 * ContactTest — the immutable contact value object.
 *
 * @BABOK Related: TBD — no requirement id for the shared contact resolver
 *                 exists in this repo's ProjectDcs; see the class docblock.
 *
 * @package ksfraser\FrontAccounting\Common\Tests\Unit\ContactResolver
 * @since   2.2.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\ContactResolver;

use ksfraser\FrontAccounting\Common\ContactResolver\Contact;
use PHPUnit\Framework\TestCase;

final class ContactTest extends TestCase
{
    public function testItCarriesThePersonAndTheRelation(): void
    {
        $contact = new Contact(
            7,
            'Alice',
            'Anderson',
            'alice@example.com',
            '555-0100',
            'C-001',
            '1 Main Street',
            'customer',
            'general',
            'CUST-1',
            12,
            false
        );

        $this->assertSame(7, $contact->getId());
        $this->assertSame('Alice', $contact->getName());
        $this->assertSame('Anderson', $contact->getName2());
        $this->assertSame('alice@example.com', $contact->getEmail());
        $this->assertSame('555-0100', $contact->getPhone());
        $this->assertSame('C-001', $contact->getRef());
        $this->assertSame('1 Main Street', $contact->getAddress());
        $this->assertSame('customer', $contact->getType());
        $this->assertSame('general', $contact->getAction());
        $this->assertSame('CUST-1', $contact->getEntityId());
        $this->assertSame(12, $contact->getContactId());
        $this->assertFalse($contact->isInactive());
    }

    public function testTheDisplayNameFallsBackToTheEmail(): void
    {
        $this->assertSame('Alice Anderson', (new Contact(1, 'Alice', 'Anderson'))->getDisplayName());
        $this->assertSame('Alice', (new Contact(1, 'Alice'))->getDisplayName());
        $this->assertSame('a@example.com', (new Contact(1, '', '', 'a@example.com'))->getDisplayName());
    }

    public function testToArrayAndFromArrayRoundTrip(): void
    {
        $contact = new Contact(3, 'Bob', '', 'bob@example.com', '', 'R-1', '', 'supplier', 'general', 'SUP-1', 9, true);

        $restored = Contact::fromArray($contact->toArray());

        $this->assertSame($contact->toArray(), $restored->toArray());
        $this->assertSame(3, $restored->getId());
        $this->assertSame(9, $restored->getContactId());
        $this->assertTrue($restored->isInactive());
    }

    public function testFromArrayAcceptsRawFaColumnNames(): void
    {
        $contact = Contact::fromArray([
            'id'         => 5,
            'name'       => 'Carol',
            'email'      => 'carol@example.com',
            'type'       => 'customer',
            'action'     => 'general',
            'contact_id' => 21,
            'inactive'   => 0,
        ]);

        $this->assertSame(5, $contact->getId());
        $this->assertSame('Carol', $contact->getName());
        $this->assertSame('customer', $contact->getType());
        $this->assertSame(21, $contact->getContactId());
        $this->assertFalse($contact->isInactive());
    }

    public function testFromArrayTreatsAMissingRelationAsNull(): void
    {
        $contact = Contact::fromArray(['id' => 5, 'name' => 'Carol']);

        $this->assertNull($contact->getContactId());
        $this->assertNull($contact->getEntityId());
        $this->assertNull($contact->getType());
        $this->assertSame('', $contact->getEmail());
    }

    public function testFromArrayReadsTheInactiveFlag(): void
    {
        $this->assertTrue(Contact::fromArray(['id' => 1, 'inactive' => 1])->isInactive());
        $this->assertTrue(Contact::fromArray(['id' => 1, 'inactive' => '1'])->isInactive());
        $this->assertFalse(Contact::fromArray(['id' => 1, 'inactive' => 0])->isInactive());
        $this->assertFalse(Contact::fromArray(['id' => 1, 'inactive' => '0'])->isInactive());
    }
}

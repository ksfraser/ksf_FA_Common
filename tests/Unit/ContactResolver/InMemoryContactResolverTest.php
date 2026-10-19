<?php
/**
 * InMemoryContactResolverTest — the non-FA implementation of the shared
 * contact resolver.
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
use ksfraser\FrontAccounting\Common\ContactResolver\InMemoryContactResolver;
use PHPUnit\Framework\TestCase;

final class InMemoryContactResolverTest extends TestCase
{
    /** @var InMemoryContactResolver */
    private $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new InMemoryContactResolver();
    }

    public function testFindByIdReturnsThePerson(): void
    {
        $this->resolver->addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com']);

        $contact = $this->resolver->findById(1);

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame(1, $contact->getId());
        $this->assertSame('alice@example.com', $contact->getEmail());
    }

    public function testFindByIdReturnsNullForAnUnknownPerson(): void
    {
        $this->assertNull($this->resolver->findById(404));
    }

    public function testFindByEmailMatchesEveryPersonWithThatAddress(): void
    {
        $this->resolver->addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'shared@example.com']);
        $this->resolver->addPerson(['id' => 2, 'name' => 'Bob', 'email' => 'shared@example.com']);
        $this->resolver->addPerson(['id' => 3, 'name' => 'Carol', 'email' => 'carol@example.com']);

        $this->assertCount(2, $this->resolver->findByEmail('shared@example.com'));
        $this->assertCount(2, $this->resolver->findByEmail('SHARED@EXAMPLE.COM'));
        $this->assertCount(0, $this->resolver->findByEmail('nobody@example.com'));
    }

    public function testFindByEmailSkipsInactivePeopleUnlessAsked(): void
    {
        $this->resolver->addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'a@example.com', 'inactive' => 1]);

        $this->assertSame([], $this->resolver->findByEmail('a@example.com'));
        $this->assertCount(1, $this->resolver->findByEmail('a@example.com', true));
    }

    public function testFindByCustomerResolvesTheCustomersContacts(): void
    {
        $this->resolver->addPerson(['id' => 1, 'name' => 'Alice']);
        $this->resolver->addPerson(['id' => 2, 'name' => 'Bob']);
        $this->resolver->addContact(1, 'customer', 'general', 'CUST-1');
        $this->resolver->addContact(2, 'supplier', 'general', 'SUP-1');

        $contacts = $this->resolver->findByCustomer('CUST-1');

        $this->assertCount(1, $contacts);
        $this->assertSame(1, $contacts[0]->getId());
        $this->assertSame('customer', $contacts[0]->getType());
        $this->assertSame('CUST-1', $contacts[0]->getEntityId());
    }

    public function testFindByCustomerHonoursTheActionFilter(): void
    {
        $this->resolver->addPerson(['id' => 1, 'name' => 'Alice']);
        $this->resolver->addContact(1, 'customer', 'general', 'CUST-1');
        $this->resolver->addContact(1, 'customer', 'delivery', 'CUST-1');

        $this->assertCount(2, $this->resolver->findByCustomer('CUST-1'));
        $this->assertCount(1, $this->resolver->findByCustomer('CUST-1', 'delivery'));
    }

    public function testFindByEntityResolvesAnyEntityType(): void
    {
        $this->resolver->addPerson(['id' => 5, 'name' => 'Branch contact']);
        $this->resolver->addContact(5, 'cust_branch', 'general', 'BR-9');

        $contacts = $this->resolver->findByEntity('cust_branch', 'BR-9');

        $this->assertCount(1, $contacts);
        $this->assertSame(5, $contacts[0]->getId());
        $this->assertSame('BR-9', $contacts[0]->getEntityId());
    }

    public function testFindContactByIdReturnsTheRelation(): void
    {
        $this->resolver->addPerson(['id' => 3, 'name' => 'Carol']);
        $contact = $this->resolver->addContact(3, 'customer', 'general', 'CUST-1');

        $resolved = $this->resolver->findContactById($contact->getContactId());

        $this->assertInstanceOf(Contact::class, $resolved);
        $this->assertSame(3, $resolved->getId());
        $this->assertSame($contact->getContactId(), $resolved->getContactId());
    }

    public function testFindContactByIdReturnsNullForAnUnknownContact(): void
    {
        $this->assertNull($this->resolver->findContactById(99));
    }

    public function testListContactCategoryIdsReturnsCategoryIds(): void
    {
        $this->resolver->addPerson(['id' => 4, 'name' => 'Dave']);
        $this->resolver->addContact(4, 'customer', 'general', 'CUST-1');
        $this->resolver->addContact(4, 'supplier', 'general', 'SUP-1');

        $categories = $this->resolver->listContactCategoryIds(4);

        $this->assertCount(2, $categories);
        $this->assertContainsOnly('int', $categories);
    }

    public function testListContactCategoryIdsIsEmptyForAPersonWithoutContacts(): void
    {
        $this->resolver->addPerson(['id' => 4, 'name' => 'Dave']);

        $this->assertSame([], $this->resolver->listContactCategoryIds(4));
    }

    public function testSearchMatchesNameEmailAndPhone(): void
    {
        $this->resolver->addPerson(['id' => 1, 'name' => 'Alice Smith', 'email' => 'alice@example.com', 'phone' => '555-0100']);
        $this->resolver->addPerson(['id' => 2, 'name' => 'Bob Jones', 'email' => 'bob@example.com']);

        $this->assertCount(1, $this->resolver->search('alice'));
        $this->assertCount(1, $this->resolver->search('bob@example'));
        $this->assertCount(0, $this->resolver->search('nobody'));
    }

    public function testSearchHonoursTheLimit(): void
    {
        $this->resolver->addPerson(['id' => 1, 'name' => 'Alice One']);
        $this->resolver->addPerson(['id' => 2, 'name' => 'Alice Two']);

        $this->assertCount(1, $this->resolver->search('alice', 1));
        $this->assertCount(2, $this->resolver->search('alice', 10));
    }

    public function testTheImplementationHasNoFaCoupling(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../../src/ContactResolver/InMemoryContactResolver.php'
        );

        $this->assertIsString($source);
        $this->assertStringNotContainsString('db_query', $source);
        $this->assertStringNotContainsString('TB_PREF', $source);
        $this->assertStringNotContainsString('get_crm_', $source);
    }
}

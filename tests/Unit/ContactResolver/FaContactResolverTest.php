<?php
/**
 * FaContactResolverTest — the FA implementation of the shared contact
 * resolver, exercised against the native CRM function stubs.
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
use ksfraser\FrontAccounting\Common\ContactResolver\FaContactResolver;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FaCrmStubs.php';

final class FaContactResolverTest extends TestCase
{
    /** @var FaContactResolver */
    private $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        FaCrmStub::reset();
        $this->resolver = new FaContactResolver();
    }

    protected function tearDown(): void
    {
        FaCrmStub::reset();
        parent::tearDown();
    }

    public function testFindByIdReturnsTheNativePerson(): void
    {
        FaCrmStub::addPerson([
            'id'    => 7,
            'name'  => 'Alice',
            'name2' => 'Anderson',
            'email' => 'alice@example.com',
            'phone' => '555-0100',
            'ref'   => 'C-001',
        ]);

        $contact = $this->resolver->findById(7);

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame(7, $contact->getId());
        $this->assertSame('Alice', $contact->getName());
        $this->assertSame('Anderson', $contact->getName2());
        $this->assertSame('alice@example.com', $contact->getEmail());
        $this->assertSame('555-0100', $contact->getPhone());
        $this->assertSame('C-001', $contact->getRef());
        $this->assertSame('Alice Anderson', $contact->getDisplayName());
    }

    public function testFindByIdReturnsNullForUnknownPerson(): void
    {
        // FA 2.4.3's get_crm_person() writes to an array offset of a false
        // db_fetch() result — a fatal on PHP 8. The resolver must absorb it.
        $this->assertNull($this->resolver->findById(999));
    }

    public function testFindByIdIgnoresNonPositiveIds(): void
    {
        $this->assertNull($this->resolver->findById(0));
        $this->assertNull($this->resolver->findById(-1));
    }

    public function testFindByCustomerReturnsTheCustomersContacts(): void
    {
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com']);
        FaCrmStub::addPerson(['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com']);
        FaCrmStub::addContact(1, 'customer', 'general', 'CUST-1');
        FaCrmStub::addContact(2, 'customer', 'general', 'CUST-2');

        $contacts = $this->resolver->findByCustomer('CUST-1');

        $this->assertCount(1, $contacts);
        $this->assertSame(1, $contacts[0]->getId());
        $this->assertSame('customer', $contacts[0]->getType());
        $this->assertSame('general', $contacts[0]->getAction());
        $this->assertSame('CUST-1', $contacts[0]->getEntityId());
    }

    public function testFindByCustomerHonoursTheActionFilter(): void
    {
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice']);
        FaCrmStub::addContact(1, 'customer', 'general', 'CUST-1');
        FaCrmStub::addContact(1, 'customer', 'delivery', 'CUST-1');

        $this->assertCount(2, $this->resolver->findByCustomer('CUST-1'));
        $this->assertCount(1, $this->resolver->findByCustomer('CUST-1', 'delivery'));
    }

    public function testFindByCustomerFallsBackToTheGeneralAction(): void
    {
        // FA's get_crm_persons() retries with the 'general' action when a
        // filtered query finds nothing.
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice']);
        FaCrmStub::addContact(1, 'customer', 'general', 'CUST-1');

        $contacts = $this->resolver->findByCustomer('CUST-1', 'invoice');

        $this->assertCount(1, $contacts);
        $this->assertSame('general', $contacts[0]->getAction());
    }

    public function testFindByCustomerReturnsEmptyForUnknownDebtor(): void
    {
        $this->assertSame([], $this->resolver->findByCustomer('NOPE'));
        $this->assertSame([], $this->resolver->findByCustomer(''));
    }

    public function testFindByEntityResolvesAnyEntityType(): void
    {
        FaCrmStub::addPerson(['id' => 5, 'name' => 'Branch contact']);
        FaCrmStub::addContact(5, 'cust_branch', 'general', 'BR-9');

        $contacts = $this->resolver->findByEntity('cust_branch', 'BR-9');

        $this->assertCount(1, $contacts);
        $this->assertSame(5, $contacts[0]->getId());
        $this->assertSame('cust_branch', $contacts[0]->getType());
        $this->assertSame('BR-9', $contacts[0]->getEntityId());
    }

    public function testFindByEmailMatchesCaseInsensitively(): void
    {
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'Alice@Example.com']);
        FaCrmStub::addContact(1, 'customer', 'general', 'CUST-1');

        $contacts = $this->resolver->findByEmail('alice@example.com');

        $this->assertCount(1, $contacts);
        $this->assertSame(1, $contacts[0]->getId());
    }

    public function testFindByEmailReturnsEveryMatch(): void
    {
        // A shared address is normal: the caller decides (FR-EM-001-03 §5).
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'shared@example.com']);
        FaCrmStub::addPerson(['id' => 2, 'name' => 'Bob', 'email' => 'shared@example.com']);
        FaCrmStub::addContact(1, 'customer', 'general', 'CUST-1');
        FaCrmStub::addContact(2, 'supplier', 'general', 'SUP-1');

        $this->assertCount(2, $this->resolver->findByEmail('shared@example.com'));
    }

    public function testFindByEmailSkipsInactivePeopleUnlessAsked(): void
    {
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'inactive' => 1]);
        FaCrmStub::addContact(1, 'customer', 'general', 'CUST-1');

        $this->assertSame([], $this->resolver->findByEmail('alice@example.com'));
        $this->assertCount(1, $this->resolver->findByEmail('alice@example.com', true));
    }

    public function testFindByEmailReturnsEmptyForAnUnknownAddress(): void
    {
        $this->assertSame([], $this->resolver->findByEmail('nobody@example.com'));
        $this->assertSame([], $this->resolver->findByEmail('   '));
    }

    public function testFindContactByIdReturnsThePersonAndTheRelation(): void
    {
        FaCrmStub::addPerson(['id' => 3, 'name' => 'Carol', 'email' => 'carol@example.com']);
        $contactId = FaCrmStub::addContact(3, 'customer', 'general', 'CUST-1');

        $contact = $this->resolver->findContactById($contactId);

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame(3, $contact->getId(), 'The person id');
        $this->assertSame($contactId, $contact->getContactId(), 'The contact relation id');
        $this->assertSame('customer', $contact->getType());
        $this->assertSame('general', $contact->getAction());
    }

    public function testFindContactByIdReturnsNullForAnUnknownContact(): void
    {
        $this->assertNull($this->resolver->findContactById(42));
        $this->assertNull($this->resolver->findContactById(0));
    }

    public function testListContactCategoryIdsReturnsCategoryIds(): void
    {
        FaCrmStub::addPerson(['id' => 4, 'name' => 'Dave']);
        FaCrmStub::addContact(4, 'customer', 'general', 'CUST-1');
        FaCrmStub::addContact(4, 'supplier', 'general', 'SUP-1');

        $categories = $this->resolver->listContactCategoryIds(4);

        $this->assertCount(2, $categories);
        $this->assertContainsOnly('int', $categories);
    }

    public function testListContactCategoryIdsIsEmptyForAPersonWithoutContacts(): void
    {
        FaCrmStub::addPerson(['id' => 4, 'name' => 'Dave']);

        $this->assertSame([], $this->resolver->listContactCategoryIds(4));
    }

    public function testSearchMatchesNameEmailAndPhone(): void
    {
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice Smith', 'email' => 'alice@example.com', 'phone' => '555-0100']);
        FaCrmStub::addPerson(['id' => 2, 'name' => 'Bob Jones', 'email' => 'bob@example.com', 'phone' => '555-0199']);
        FaCrmStub::addContact(1, 'customer', 'general', 'CUST-1');
        FaCrmStub::addContact(2, 'customer', 'general', 'CUST-2');

        $this->assertCount(1, $this->resolver->search('alice'));
        $this->assertCount(1, $this->resolver->search('bob@example'));
        $this->assertCount(1, $this->resolver->search('555-0199'));
        $this->assertCount(0, $this->resolver->search('nobody'));
    }

    public function testSearchHonoursTheLimit(): void
    {
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice One']);
        FaCrmStub::addPerson(['id' => 2, 'name' => 'Alice Two']);
        FaCrmStub::addContact(1, 'customer', 'general', 'CUST-1');
        FaCrmStub::addContact(2, 'customer', 'general', 'CUST-2');

        $this->assertCount(1, $this->resolver->search('alice', 1));
        $this->assertCount(2, $this->resolver->search('alice', 10));
    }

    public function testSearchReturnsNothingForAnEmptyQuery(): void
    {
        $this->assertSame([], $this->resolver->search('   '));
        $this->assertSame([], $this->resolver->search('alice', 0));
    }

    public function testTheResolverNeverWritesSqlAgainstFaCoreTables(): void
    {
        // The rule (AGENTS_ARCH.md §12.2): native FA functions only.
        $source = file_get_contents(
            __DIR__ . '/../../../src/ContactResolver/FaContactResolver.php'
        );

        $this->assertIsString($source);
        $this->assertStringNotContainsString('db_query', $source);
        $this->assertStringNotContainsString('TB_PREF', $source);
        $this->assertStringNotContainsString('SELECT ', $source);
        $this->assertStringNotContainsString('INSERT ', $source);
        $this->assertStringNotContainsString('UPDATE ', $source);
        $this->assertStringNotContainsString('DELETE ', $source);
    }
}

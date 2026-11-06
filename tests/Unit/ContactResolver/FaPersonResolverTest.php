<?php
/**
 * FaPersonResolverTest — the FA implementation of the shared person resolver,
 * exercised against the native CRM function stubs.
 *
 * @package ksfraser\FrontAccounting\Common\Tests\Unit\ContactResolver
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\ContactResolver;

use ksfraser\FrontAccounting\Common\ContactResolver\Contact;
use ksfraser\FrontAccounting\Common\ContactResolver\FaPersonResolver;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FaCrmStubs.php';

final class FaPersonResolverTest extends TestCase
{
    /** @var FaPersonResolver */
    private $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        FaCrmStub::reset();
        $this->resolver = new FaPersonResolver();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        FaCrmStub::reset();
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
        $this->assertNull($this->resolver->findById(999));
    }

    public function testFindByIdIgnoresNonPositiveIds(): void
    {
        $this->assertNull($this->resolver->findById(0));
        $this->assertNull($this->resolver->findById(-1));
    }

    public function testFindByIdDoesNotSetContactId(): void
    {
        FaCrmStub::addPerson(['id' => 7, 'name' => 'Alice']);
        $contact = $this->resolver->findById(7);
        $this->assertNull($contact->getContactId());
    }

    public function testFindByEmailReturnsEveryMatch(): void
    {
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'shared@example.com']);
        FaCrmStub::addPerson(['id' => 2, 'name' => 'Bob', 'email' => 'shared@example.com']);
        FaCrmStub::addContact(1, 'customer', 'general', 'CUST-1');
        FaCrmStub::addContact(2, 'supplier', 'general', 'SUP-1');

        $contacts = $this->resolver->findByEmail('shared@example.com');

        $this->assertCount(2, $contacts);
        $this->assertSame(1, $contacts[0]->getId());
        $this->assertSame(2, $contacts[1]->getId());
    }

    public function testFindByEmailMatchesCaseInsensitively(): void
    {
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'Alice@Example.com']);
        FaCrmStub::addContact(1, 'customer', 'general', 'CUST-1');

        $contacts = $this->resolver->findByEmail('alice@example.com');

        $this->assertCount(1, $contacts);
        $this->assertSame(1, $contacts[0]->getId());
    }

    public function testFindByEmailSkipsInactivePeopleUnlessAsked(): void
    {
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'inactive' => 1]);
        FaCrmStub::addContact(1, 'customer', 'general', 'CUST-1');

        $this->assertSame([], $this->resolver->findByEmail('alice@example.com'));
        $this->assertCount(1, $this->resolver->findByEmail('alice@example.com', true));
    }

    public function testFindByEmailReturnsEmptyForUnknownAddress(): void
    {
        $this->assertSame([], $this->resolver->findByEmail('nobody@example.com'));
        $this->assertSame([], $this->resolver->findByEmail('   '));
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

    public function testListContactCategoryIdsReturnsCategoryIds(): void
    {
        FaCrmStub::addPerson(['id' => 4, 'name' => 'Dave']);
        FaCrmStub::addContact(4, 'customer', 'general', 'CUST-1');
        FaCrmStub::addContact(4, 'supplier', 'general', 'SUP-1');

        $categories = $this->resolver->listContactCategoryIds(4);

        $this->assertCount(2, $categories);
        $this->assertContainsOnly('int', $categories);
    }

    public function testListContactCategoryIdsReturnsEmptyForPersonWithoutContacts(): void
    {
        FaCrmStub::addPerson(['id' => 4, 'name' => 'Dave']);

        $this->assertSame([], $this->resolver->listContactCategoryIds(4));
    }

    public function testListContactCategoryIdsIgnoresNonPositiveIds(): void
    {
        $this->assertSame([], $this->resolver->listContactCategoryIds(0));
        $this->assertSame([], $this->resolver->listContactCategoryIds(-1));
    }

    public function testResolverNeverWritesSqlAgainstFaCoreTables(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../../src/ContactResolver/FaPersonResolver.php'
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

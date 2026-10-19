<?php
/**
 * ContactResolverTest — the front door: hook dispatch, fallback, and the
 * contract's own guarantees.
 *
 * The hook stub is this package's existing one (tests/Unit/App/FaHookStub.php),
 * so the hook path is exercised the same way the preference tests exercise it.
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
use ksfraser\FrontAccounting\Common\ContactResolver\ContactResolver;
use ksfraser\FrontAccounting\Common\ContactResolver\Contract\ContactResolverHookContract;
use ksfraser\FrontAccounting\Common\ContactResolver\Contract\ContactResolverInterface;
use ksfraser\FrontAccounting\Common\ContactResolver\FaContactResolver;
use ksfraser\FrontAccounting\Common\ContactResolver\InMemoryContactResolver;
use Ksfraser\Frontaccounting\Common\Tests\Unit\FaHookStub;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../App/FaHookStub.php';
require_once __DIR__ . '/FaCrmStubs.php';

final class ContactResolverTest extends TestCase
{
    /** @var InMemoryContactResolver */
    private $fallback;

    /** @var ContactResolver */
    private $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        FaHookStub::reset();
        FaCrmStub::reset();
        $this->fallback = new InMemoryContactResolver();
        $this->resolver  = new ContactResolver($this->fallback);
    }

    protected function tearDown(): void
    {
        FaHookStub::reset();
        FaCrmStub::reset();
        parent::tearDown();
    }

    public function testTheDefaultImplementationIsTheFaOne(): void
    {
        $resolver = new ContactResolver();

        $this->assertInstanceOf(FaContactResolver::class, $resolver->getFallback());
    }

    public function testTheResolverImplementsTheContract(): void
    {
        $this->assertInstanceOf(ContactResolverInterface::class, $this->resolver);
    }

    public function testTheFallbackAnswersWhenNoListenerResponds(): void
    {
        $this->fallback->addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com']);

        $contacts = $this->resolver->findByEmail('alice@example.com');

        $this->assertCount(1, $contacts);
        $this->assertSame('Alice', $contacts[0]->getName());
    }

    public function testAListenerTakesOverTheLookup(): void
    {
        FaHookStub::respondTo(ContactResolverHookContract::HOOK_RESOLVE, function (array &$data): void {
            $data[ContactResolverHookContract::KEY_HANDLED] = true;
            $data[ContactResolverHookContract::KEY_RESULT] = [
                ['id' => 42, 'name' => 'From The Platform', 'email' => 'platform@example.com'],
            ];
        });

        $this->fallback->addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com']);

        $contacts = $this->resolver->findByEmail('alice@example.com');

        $this->assertCount(1, $contacts);
        $this->assertSame(42, $contacts[0]->getId());
        $this->assertSame('From The Platform', $contacts[0]->getName());
    }

    public function testAListenerReceivesTheOperationAndTheArguments(): void
    {
        $seen = null;

        FaHookStub::respondTo(ContactResolverHookContract::HOOK_RESOLVE, function (array &$data) use (&$seen): void {
            $seen = $data;
            $data[ContactResolverHookContract::KEY_HANDLED] = true;
            $data[ContactResolverHookContract::KEY_RESULT] = [];
        });

        $this->resolver->findByCustomer('CUST-1', 'general');

        $this->assertIsArray($seen);
        $this->assertSame(ContactResolverHookContract::OP_BY_CUSTOMER, $seen[ContactResolverHookContract::KEY_OPERATION]);
        $this->assertSame('CUST-1', $seen[ContactResolverHookContract::KEY_ARGUMENTS]['debtor_no']);
        $this->assertSame('general', $seen[ContactResolverHookContract::KEY_ARGUMENTS]['action']);
    }

    public function testAnUnhandledListenerFallsBackToTheImplementation(): void
    {
        FaHookStub::respondTo(ContactResolverHookContract::HOOK_RESOLVE, function (array &$data): void {
            // A listener that does not understand the question leaves it alone.
            $data[ContactResolverHookContract::KEY_RESULT] = [['id' => 99]];
        });

        $this->fallback->addPerson(['id' => 1, 'name' => 'Alice']);

        $contact = $this->resolver->findById(1);

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame(1, $contact->getId());
    }

    public function testAListenerAnsweringWithNothingYieldsNoContacts(): void
    {
        FaHookStub::respondTo(ContactResolverHookContract::HOOK_RESOLVE, function (array &$data): void {
            $data[ContactResolverHookContract::KEY_HANDLED] = true;
            $data[ContactResolverHookContract::KEY_RESULT] = null;
        });

        $this->fallback->addPerson(['id' => 1, 'name' => 'Alice']);

        $this->assertNull($this->resolver->findById(1));
    }

    public function testFindByIdReturnsNullWhenNothingIsFound(): void
    {
        $this->assertNull($this->resolver->findById(404));
    }

    public function testFindByEntityPassesThroughTheFallback(): void
    {
        $this->fallback->addPerson(['id' => 5, 'name' => 'Branch contact']);
        $this->fallback->addContact(5, 'cust_branch', 'general', 'BR-9');

        $contacts = $this->resolver->findByEntity('cust_branch', 'BR-9');

        $this->assertCount(1, $contacts);
        $this->assertSame('BR-9', $contacts[0]->getEntityId());
    }

    public function testListContactCategoryIdsIsHydratedFromTheHook(): void
    {
        FaHookStub::respondTo(ContactResolverHookContract::HOOK_RESOLVE, function (array &$data): void {
            $data[ContactResolverHookContract::KEY_HANDLED] = true;
            $data[ContactResolverHookContract::KEY_RESULT] = [7, 9];
        });

        $this->assertSame([7, 9], $this->resolver->listContactCategoryIds(4));
    }

    public function testListContactCategoryIdsFallsBackToTheImplementation(): void
    {
        $this->fallback->addPerson(['id' => 4, 'name' => 'Dave']);
        $this->fallback->addContact(4, 'customer', 'general', 'CUST-1');

        $categories = $this->resolver->listContactCategoryIds(4);

        $this->assertCount(1, $categories);
        $this->assertContainsOnly('int', $categories);
    }

    public function testSearchPassesThroughTheFallback(): void
    {
        $this->fallback->addPerson(['id' => 1, 'name' => 'Alice Smith']);

        $this->assertCount(1, $this->resolver->search('alice'));
    }

    public function testTheHookContractNamesTheOperations(): void
    {
        $this->assertSame('ksf_contact_resolve', ContactResolverHookContract::HOOK_RESOLVE);
        $this->assertSame('operation', ContactResolverHookContract::KEY_OPERATION);
        $this->assertSame('arguments', ContactResolverHookContract::KEY_ARGUMENTS);
        $this->assertSame('result', ContactResolverHookContract::KEY_RESULT);
        $this->assertSame('handled', ContactResolverHookContract::KEY_HANDLED);
        $this->assertSame('by_id', ContactResolverHookContract::OP_BY_ID);
        $this->assertSame('by_email', ContactResolverHookContract::OP_BY_EMAIL);
        $this->assertSame('by_customer', ContactResolverHookContract::OP_BY_CUSTOMER);
        $this->assertSame('by_entity', ContactResolverHookContract::OP_BY_ENTITY);
        $this->assertSame('by_contact_id', ContactResolverHookContract::OP_BY_CONTACT_ID);
        $this->assertSame('contact_category_ids', ContactResolverHookContract::OP_CONTACT_CATEGORY_IDS);
        $this->assertSame('search', ContactResolverHookContract::OP_SEARCH);
    }

    public function testTheResolverIsUsableWithoutAnyFaRuntime(): void
    {
        // Outside FA there is no hook_invoke_all() and no FA database: the
        // resolver must still answer, from the injected implementation.
        $resolver = new ContactResolver(new InMemoryContactResolver());

        $this->assertSame([], $resolver->findByEmail('nobody@example.com'));
        $this->assertNull($resolver->findById(1));
    }

    public function testTheFaImplementationReturnsEmptyWhenNothingMatches(): void
    {
        // A native call that finds nothing — or that throws (FA's
        // get_crm_person() on a missing id) — must degrade to "no contact",
        // never to a fatal.
        $resolver = new FaContactResolver();

        $this->assertNull($resolver->findById(1));
        $this->assertSame([], $resolver->findByEmail('a@example.com'));
        $this->assertSame([], $resolver->findByCustomer('CUST-1'));
        $this->assertSame([], $resolver->findByEntity('customer', 'CUST-1'));
        $this->assertNull($resolver->findContactById(1));
        $this->assertSame([], $resolver->listContactCategoryIds(1));
        $this->assertSame([], $resolver->search('a'));
    }

    public function testAContactFromTheHookIsHydrated(): void
    {
        FaHookStub::respondTo(ContactResolverHookContract::HOOK_RESOLVE, function (array &$data): void {
            $data[ContactResolverHookContract::KEY_HANDLED] = true;
            $data[ContactResolverHookContract::KEY_RESULT] = [
                new Contact(11, 'Instance', 'From A Listener'),
            ];
        });

        $contact = $this->resolver->findById(11);

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame(11, $contact->getId());
        $this->assertSame('Instance', $contact->getName());
    }
}

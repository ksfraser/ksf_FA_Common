<?php
/**
 * PersonResolverTest — the hook front door for person-only resolution,
 * exercised against stubs and doubles.
 *
 * @package ksfraser\FrontAccounting\Common\Tests\Unit\ContactResolver
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\ContactResolver;

use ksfraser\FrontAccounting\Common\ContactResolver\Contact;
use ksfraser\FrontAccounting\Common\ContactResolver\Contract\ContactResolverHookContract;
use ksfraser\FrontAccounting\Common\ContactResolver\Contract\PersonResolverInterface;
use Ksfraser\FrontAccounting\Common\Tests\Unit\FaHookStub;
use ksfraser\FrontAccounting\Common\ContactResolver\FaPersonResolver;
use ksfraser\FrontAccounting\Common\ContactResolver\PersonResolver;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FaCrmStubs.php';
require_once __DIR__ . '/../App/FaHookStub.php';

final class PersonResolverTest extends TestCase
{
    /** @var PersonResolver */
    private $resolver;

    /** @var FaPersonResolver */
    private $fallback;

    protected function setUp(): void
    {
        parent::setUp();
        FaHookStub::reset();
        FaCrmStub::reset();
        $this->fallback = new FaPersonResolver();
        $this->resolver = new PersonResolver($this->fallback);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        FaHookStub::reset();
        FaCrmStub::reset();
    }

    public function testTheDefaultImplementationIsTheFaOne(): void
    {
        $resolver = new PersonResolver();

        $this->assertInstanceOf(FaPersonResolver::class, $resolver->getFallback());
    }

    public function testTheResolverImplementsTheContract(): void
    {
        $this->assertInstanceOf(PersonResolverInterface::class, $this->resolver);
    }

    public function testTheFallbackAnswersWhenNoListenerResponds(): void
    {
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com']);

        $contact = $this->resolver->findById(1);

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame(1, $contact->getId());
        $this->assertSame('Alice', $contact->getName());
    }

    public function testAListenerTakesOverTheLookup(): void
    {
        FaHookStub::respondTo(ContactResolverHookContract::HOOK_RESOLVE, function (array &$data): void {
            $data[ContactResolverHookContract::KEY_HANDLED] = true;
            $data[ContactResolverHookContract::KEY_RESULT] = [
                ['id' => 42, 'name' => 'From The Platform', 'email' => 'platform@example.com'],
            ];
        });

        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com']);

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

        $this->resolver->search('alice', 10);

        $this->assertIsArray($seen);
        $this->assertSame(ContactResolverHookContract::OP_SEARCH, $seen[ContactResolverHookContract::KEY_OPERATION]);
        $this->assertSame('alice', $seen[ContactResolverHookContract::KEY_ARGUMENTS]['query']);
        $this->assertSame(10, $seen[ContactResolverHookContract::KEY_ARGUMENTS]['limit']);
    }

    public function testAnUnhandledListenerFallsBackToTheImplementation(): void
    {
        FaHookStub::respondTo(ContactResolverHookContract::HOOK_RESOLVE, function (array &$data): void {
            $data[ContactResolverHookContract::KEY_RESULT] = [['id' => 99]];
        });

        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice']);

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

        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice']);

        $this->assertNull($this->resolver->findById(1));
    }

    public function testFindByIdReturnsNullWhenNothingIsFound(): void
    {
        $this->assertNull($this->resolver->findById(404));
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
        FaCrmStub::addPerson(['id' => 4, 'name' => 'Dave']);
        FaCrmStub::addContact(4, 'customer', 'general', 'CUST-1');

        $categories = $this->resolver->listContactCategoryIds(4);

        $this->assertCount(1, $categories);
        $this->assertContainsOnly('int', $categories);
    }

    public function testSearchPassesThroughTheFallback(): void
    {
        FaCrmStub::addPerson(['id' => 1, 'name' => 'Alice Smith']);
        FaCrmStub::addContact(1, 'customer', 'general', 'CUST-1');

        $this->assertCount(1, $this->resolver->search('alice'));
    }

    public function testTheResolverIsUsableWithoutAnyFaRuntime(): void
    {
        // Outside FA there is no hook_invoke_all() and no FA database: the
        // resolver must still answer, from the injected implementation.
        $resolver = new PersonResolver(new FaPersonResolver());

        $this->assertSame([], $resolver->findByEmail('nobody@example.com'));
        $this->assertNull($resolver->findById(1));
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

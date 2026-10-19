<?php
/**
 * ContactResolver — the front door of the shared contact resolver.
 *
 * This is the class a module injects and calls.  It does two things:
 *
 *  1. It asks the platform first: the `ksf_contact_resolve` hook is dispatched
 *     with the lookup's operation and arguments, and a listener that answers
 *     (sets 'handled' => true) takes over.  That is how a non-FA platform
 *     (WordPress, Nextcloud, a standalone service) supplies its own
 *     implementation without changing any consumer.
 *  2. It falls back to the injected implementation — FaContactResolver by
 *     default, which calls FA's native functions.
 *
 * House style: the same hook-then-fallback shape as
 * src/Preference/PreferenceRepository.php (hook_invoke_all first, local
 * implementation second), with the wire named by
 * ContactResolverHookContract.
 *
 * A listener's absence is a NORMAL state, never an error: the resolver is a
 * shared seam in ksf_FA_Common (a Composer package that is always loadable)
 * and is never gated on another module being activated.  Outside FA — where
 * hook_invoke_all() does not exist — the resolver simply uses its fallback.
 *
 * @BABOK Related: TBD — no requirement id for the shared contact resolver
 *                 exists in this repo's ProjectDcs (doc/ProjectDocuments/
 *                 ProjectDcs is empty); the decision and the native function
 *                 list are recorded in ksf_Architecture_Docs AGENTS_ARCH.md
 *                 §12.2. The closest estate requirement is FR-EM-001-03
 *                 (ksf_Architecture_Docs/Requirements/).
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver
 * @since   2.2.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ContactResolver;

use ksfraser\FrontAccounting\Common\ContactResolver\Contract\ContactResolverHookContract;
use ksfraser\FrontAccounting\Common\ContactResolver\Contract\ContactResolverInterface;

/**
 * Hook-dispatching contact resolver.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver
 * @since   2.2.0
 */
final class ContactResolver implements ContactResolverInterface
{
    /** @var ContactResolverInterface The implementation used when no listener answers */
    private $fallback;

    /**
     * @param ContactResolverInterface|null $fallback Implementation used when
     *        no hook listener answers. Defaults to FaContactResolver, which
     *        calls FA's native functions.
     */
    public function __construct(?ContactResolverInterface $fallback = null)
    {
        $this->fallback = $fallback !== null ? $fallback : new FaContactResolver();
    }

    /**
     * The implementation used when no hook listener answers.
     *
     * @return ContactResolverInterface
     */
    public function getFallback(): ContactResolverInterface
    {
        return $this->fallback;
    }

    /**
     * Resolve one person (contact) by its person id.
     *
     * @param int $personId Person id
     *
     * @return Contact|null The person, or null when there is no such person
     */
    public function findById(int $personId): ?Contact
    {
        $contacts = $this->dispatchContacts(
            ContactResolverHookContract::OP_BY_ID,
            ['person_id' => $personId],
            function () use ($personId): array {
                $contact = $this->fallback->findById($personId);

                return $contact === null ? [] : [$contact];
            }
        );

        return $contacts[0] ?? null;
    }

    /**
     * Resolve every person whose email address matches.
     *
     * @param string $email           Email address to match
     * @param bool   $includeInactive Include people flagged inactive
     *
     * @return Contact[] Matching people (empty when none)
     */
    public function findByEmail(string $email, bool $includeInactive = false): array
    {
        return $this->dispatchContacts(
            ContactResolverHookContract::OP_BY_EMAIL,
            ['email' => $email, 'include_inactive' => $includeInactive],
            function () use ($email, $includeInactive): array {
                return $this->fallback->findByEmail($email, $includeInactive);
            }
        );
    }

    /**
     * Resolve the contacts of a debtor (an FA customer).
     *
     * @param string      $debtorNo Debtor id
     * @param string|null $action   Optional contact action to narrow to
     *
     * @return Contact[] The customer's contacts (empty when none)
     */
    public function findByCustomer(string $debtorNo, ?string $action = null): array
    {
        return $this->dispatchContacts(
            ContactResolverHookContract::OP_BY_CUSTOMER,
            ['debtor_no' => $debtorNo, 'action' => $action],
            function () use ($debtorNo, $action): array {
                return $this->fallback->findByCustomer($debtorNo, $action);
            }
        );
    }

    /**
     * Resolve the contacts of any entity.
     *
     * @param string      $type     Contact type
     * @param string      $entityId The related entity's id
     * @param string|null $action   Optional contact action to narrow to
     *
     * @return Contact[] The entity's contacts (empty when none)
     */
    public function findByEntity(string $type, string $entityId, ?string $action = null): array
    {
        return $this->dispatchContacts(
            ContactResolverHookContract::OP_BY_ENTITY,
            ['type' => $type, 'entity_id' => $entityId, 'action' => $action],
            function () use ($type, $entityId, $action): array {
                return $this->fallback->findByEntity($type, $entityId, $action);
            }
        );
    }

    /**
     * Resolve one contact relation row.
     *
     * @param int $contactId Contact relation id
     *
     * @return Contact|null The contact, or null when there is no such contact
     */
    public function findContactById(int $contactId): ?Contact
    {
        $contacts = $this->dispatchContacts(
            ContactResolverHookContract::OP_BY_CONTACT_ID,
            ['contact_id' => $contactId],
            function () use ($contactId): array {
                $contact = $this->fallback->findContactById($contactId);

                return $contact === null ? [] : [$contact];
            }
        );

        return $contacts[0] ?? null;
    }

    /**
     * Return the contact CATEGORY ids of a person.
     *
     * @param int $personId Person id
     *
     * @return int[] Category ids (empty when the person has no contacts)
     */
    public function listContactCategoryIds(int $personId): array
    {
        $result = $this->dispatch(
            ContactResolverHookContract::OP_CONTACT_CATEGORY_IDS,
            ['person_id' => $personId],
            function () use ($personId): array {
                return $this->fallback->listContactCategoryIds($personId);
            }
        );

        $categories = [];
        foreach ($result as $id) {
            if (is_numeric($id)) {
                $categories[] = (int) $id;
            }
        }

        return $categories;
    }

    /**
     * Search people by name, email or phone.
     *
     * @param string $query          Search term
     * @param int    $limit          Maximum rows to return
     * @param bool   $includeInactive Include people flagged inactive
     *
     * @return Contact[] Matching people (empty when none)
     */
    public function search(string $query, int $limit = 20, bool $includeInactive = false): array
    {
        return $this->dispatchContacts(
            ContactResolverHookContract::OP_SEARCH,
            ['query' => $query, 'limit' => $limit, 'include_inactive' => $includeInactive],
            function () use ($query, $limit, $includeInactive): array {
                return $this->fallback->search($query, $limit, $includeInactive);
            }
        );
    }

    /**
     * Dispatch a lookup to the platform, falling back to the implementation.
     *
     * @param string   $operation Operation name (ContactResolverHookContract::OP_*)
     * @param array    $arguments Lookup arguments
     * @param callable $fallback  Produces the answer when no listener answers
     *
     * @return array The answer (a list)
     */
    private function dispatch(string $operation, array $arguments, callable $fallback): array
    {
        if (function_exists('hook_invoke_all')) {
            $payload = [
                ContactResolverHookContract::KEY_OPERATION => $operation,
                ContactResolverHookContract::KEY_ARGUMENTS => $arguments,
                ContactResolverHookContract::KEY_RESULT    => null,
                ContactResolverHookContract::KEY_HANDLED   => false,
            ];

            hook_invoke_all(ContactResolverHookContract::HOOK_RESOLVE, $payload);

            if (!empty($payload[ContactResolverHookContract::KEY_HANDLED])) {
                $result = $payload[ContactResolverHookContract::KEY_RESULT];
                if (\is_array($result)) {
                    return $result;
                }
                if ($result === null) {
                    return [];
                }
            }
        }

        return $fallback();
    }

    /**
     * Dispatch a lookup and hydrate the answer into Contact objects.
     *
     * A listener may answer with Contact instances or with plain arrays (the
     * hook payload is array-based, so arrays are the wire format).
     *
     * @param string   $operation Operation name
     * @param array    $arguments Lookup arguments
     * @param callable $fallback  Produces the answer when no listener answers
     *
     * @return Contact[] The answer
     */
    private function dispatchContacts(string $operation, array $arguments, callable $fallback): array
    {
        $contacts = [];
        foreach ($this->dispatch($operation, $arguments, $fallback) as $item) {
            if ($item instanceof Contact) {
                $contacts[] = $item;
                continue;
            }
            if (\is_array($item)) {
                $contacts[] = Contact::fromArray($item);
            }
        }

        return $contacts;
    }
}

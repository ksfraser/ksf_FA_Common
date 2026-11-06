<?php
/**
 * PersonResolver — the front door of the shared person resolver.
 *
 * This is the class a module injects and calls for person-only lookups.  It
 * does two things:
 *
 *  1. It asks the platform first: the `ksf_contact_resolve` hook is dispatched
 *     with the lookup's operation and arguments, and a listener that answers
 *     (sets 'handled' => true) takes over.
 *  2. It falls back to the injected implementation — FaPersonResolver by
 *     default, which calls FA's native functions.
 *
 * A listener's absence is a NORMAL state, never an error: the resolver is a
 * shared seam in ksf_FA_Common (a Composer package that is always loadable)
 * and is never gated on another module being activated.  Outside FA — where
 * hook_invoke_all() does not exist — the resolver simply uses its fallback.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ContactResolver;

use ksfraser\FrontAccounting\Common\ContactResolver\Contract\ContactResolverHookContract;
use ksfraser\FrontAccounting\Common\ContactResolver\Contract\PersonResolverInterface;

/**
 * Hook-dispatching person resolver.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver
 * @since   2.3.0
 */
final class PersonResolver implements PersonResolverInterface
{
    /** @var PersonResolverInterface The implementation used when no listener answers */
    private $fallback;

    /**
     * @param PersonResolverInterface|null $fallback Implementation used when
     *        no hook listener answers. Defaults to FaPersonResolver, which
     *        calls FA's native functions.
     */
    public function __construct(?PersonResolverInterface $fallback = null)
    {
        $this->fallback = $fallback !== null ? $fallback : new FaPersonResolver();
    }

    /**
     * The implementation used when no hook listener answers.
     *
     * @return PersonResolverInterface
     */
    public function getFallback(): PersonResolverInterface
    {
        return $this->fallback;
    }

    /**
     * Resolve one person by its person id.
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

<?php
/**
 * FaContactResolver — the FrontAccounting implementation of the shared contact
 * resolver.
 *
 * HARD RULE (AGENTS_ARCH.md §12.2): this adapter calls FA's NATIVE FUNCTIONS
 * only.  It never runs raw SQL against an FA core table.  The functions used,
 * all verified against FA 2.4.3 `includes/db/crm_contacts_db.inc`:
 *
 *   get_crm_person($id)                      one person (+ their contact ids)
 *   get_crm_persons($type,$action,$entity,    the relation query: crm_persons
 *                $person,$unique)             JOIN crm_contacts JOIN
 *                                            crm_categories
 *   get_crm_contact($id)                      one contact relation row
 *   get_person_contacts($id)                  a person's contact CATEGORY ids
 *
 * This is the same shape and the same discipline as
 * ksfraser\CommonDb\Adapter\FaDbAdapter (the DbConnectionInterface precedent):
 * one contract, one FA implementation that is mandatory at runtime, and one
 * non-FA implementation for tests/standalone (InMemoryContactResolver).
 *
 * VERIFIED NATIVE-COVERAGE GAPS (FA 2.4.3) — read before relying on a lookup:
 *
 *  1. FA exposes NO native person-by-email lookup.  The only native way to
 *     enumerate people is get_crm_persons(), which INNER JOINs crm_contacts —
 *     so it can only see people that have at least one contact relation.  A
 *     person with no relation (e.g. one created by an inbound-email
 *     match-or-create flow, which deliberately creates an UNLINKED person) is
 *     NOT reachable by email through the native API.  This class therefore
 *     scans the native relation query and matches on email; a platform that
 *     needs unlinked people must supply its own implementation of
 *     ContactResolverInterface (DI) or answer the `ksf_contact_resolve` hook.
 *
 *  2. FA's native CRM functions never expose `crm_contacts`.`entity_id`
 *     (get_crm_persons() selects t.*, p.*, r.id AS contact_id; get_crm_contact()
 *     selects t.type, t.action, p.*, r.person_id, r.id).  The relation can
 *     therefore only be resolved DEBTOR -> CONTACTS, never PERSON -> DEBTOR,
 *     without writing SQL against an FA core table.  That direction is
 *     deliberately absent from the contract.
 *
 * Neither gap is worked around with SQL: a platform that needs them uses the
 * contract's other implementation.
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

use ksfraser\FrontAccounting\Common\ContactResolver\Contract\ContactResolverInterface;

/**
 * Contact resolver backed by FA's native CRM functions.
 *
 * Person-only operations (findById, findByEmail, search, listContactCategoryIds)
 * are delegated to FaPersonResolver.  Contact-relation operations
 * (findContactById, findByCustomer, findByEntity) are handled here directly
 * because they need the crm_contacts relation data that get_crm_contact() and
 * get_crm_persons() provide.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver
 * @since   2.2.0
 */
final class FaContactResolver implements ContactResolverInterface
{
    /** @var FaPersonResolver Person-only resolver used internally */
    private $personResolver;

    /** @var bool Whether the native CRM include has already been attempted */
    private static $includeAttempted = false;

    /**
     * @param FaPersonResolver|null $personResolver Person resolver to delegate
     *        to. Defaults to a new FaPersonResolver.
     */
    public function __construct(?FaPersonResolver $personResolver = null)
    {
        $this->personResolver = $personResolver !== null ? $personResolver : new FaPersonResolver();
    }

    /**
     * Resolve one person (contact) by its person id.
     *
     * Delegated to FaPersonResolver — person-only lookup, no relation context.
     *
     * @param int $personId Person id
     *
     * @return Contact|null The person, or null when there is no such person
     */
    public function findById(int $personId): ?Contact
    {
        return $this->personResolver->findById($personId);
    }

    /**
     * Resolve every person whose email address matches.
     *
     * Delegated to FaPersonResolver.
     *
     * @param string $email           Email address to match
     * @param bool   $includeInactive Include people flagged inactive
     *
     * @return Contact[] Matching people (empty when none)
     */
    public function findByEmail(string $email, bool $includeInactive = false): array
    {
        return $this->personResolver->findByEmail($email, $includeInactive);
    }

    /**
     * Search people by name, email or phone.
     *
     * Delegated to FaPersonResolver.
     *
     * @param string $query          Search term
     * @param int    $limit          Maximum rows to return
     * @param bool   $includeInactive Include people flagged inactive
     *
     * @return Contact[] Matching people (empty when none)
     */
    public function search(string $query, int $limit = 20, bool $includeInactive = false): array
    {
        return $this->personResolver->search($query, $limit, $includeInactive);
    }

    /**
     * Return the contact CATEGORY ids of a person.
     *
     * Delegated to FaPersonResolver.
     *
     * @param int $personId Person id
     *
     * @return int[] Category ids (empty when the person has no contacts)
     */
    public function listContactCategoryIds(int $personId): array
    {
        return $this->personResolver->listContactCategoryIds($personId);
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
        return $this->findByEntity('customer', $debtorNo, $action);
    }

    /**
     * Resolve the contacts of any entity.
     *
     * @param string      $type     Contact type ('customer', 'supplier', …)
     * @param string      $entityId The related entity's id
     * @param string|null $action   Optional contact action to narrow to
     *
     * @return Contact[] The entity's contacts (empty when none)
     */
    public function findByEntity(string $type, string $entityId, ?string $action = null): array
    {
        $type     = trim($type);
        $entityId = trim($entityId);
        if ($type === '' || $entityId === '') {
            return [];
        }

        $this->ensureNativeFunctions();

        $result = $this->callFa('get_crm_persons', [$type, $action, $entityId, null, false]);

        $contacts = [];
        foreach ($this->rowsFrom($result) as $row) {
            // The native relation query does not select crm_contacts.entity_id
            // (see the class docblock); the entity we asked for IS the id.
            if (!isset($row['entity_id'])) {
                $row['entity_id'] = $entityId;
            }
            $contacts[] = Contact::fromArray($row);
        }

        return $contacts;
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
        if ($contactId <= 0) {
            return null;
        }

        $this->ensureNativeFunctions();

        $row = $this->callFa('get_crm_contact', [$contactId]);
        ksf_log('square', 'debug', 'get_crm_contact('.$contactId.') result', ['row' => $row, 'is_array' => is_array($row), 'has_person_id' => is_array($row) && isset($row['person_id'])]);
        if (!\is_array($row) || !isset($row['person_id'])) {
            return null;
        }

        // get_crm_contact() returns t.type, t.action, p.*, r.person_id, r.id —
        // so 'id' is the CONTACT id and 'person_id' is the PERSON id.
        return Contact::fromArray([
            'id'         => $row['person_id'],
            'name'       => $row['name'] ?? '',
            'name2'      => $row['name2'] ?? '',
            'email'      => $row['email'] ?? '',
            'phone'      => $row['phone'] ?? '',
            'ref'        => $row['ref'] ?? '',
            'address'    => $row['address'] ?? '',
            'type'       => $row['type'] ?? null,
            'action'     => $row['action'] ?? null,
            'contact_id' => $row['id'] ?? null,
            'inactive'   => $row['inactive'] ?? false,
        ]);
    }

    /**
     * Call a native FA function defensively.
     *
     * A missing function, or one that throws (FA's get_crm_person() writes to
     * an array offset of a false result when the id does not exist, which is a
     * fatal on PHP 8), must never take a page down: the resolver degrades to
     * "no contact".
     *
     * @param string $function  Native function name
     * @param array  $arguments Call arguments
     *
     * @return mixed The function's return value, or null when it is absent
     *               or throws
     */
    private function callFa(string $function, array $arguments)
    {
        if (!function_exists($function)) {
            return null;
        }

        try {
            return call_user_func_array($function, $arguments);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Normalise a native result into a list of rows.
     *
     * get_crm_persons() returns a db result set (an object) that must be read
     * with FA's db_fetch(); get_customer_contacts() returns a plain array of
     * rows.  Both are accepted.
     *
     * @param mixed $result Native return value
     *
     * @return array<int, array> Rows
     */
    private function rowsFrom($result): array
    {
        if (\is_array($result)) {
            $rows = [];
            foreach ($result as $row) {
                if (\is_array($row)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        if (\is_object($result) && function_exists('db_fetch')) {
            $rows = [];
            while (($row = db_fetch($result)) !== false && $row !== null) {
                if (\is_array($row)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        return [];
    }

    /**
     * Make sure FA's native CRM functions are loaded.
     *
     * `includes/db/crm_contacts_db.inc` is NOT auto-loaded by
     * `includes/main.inc`; a module calling these functions must include it.
     * The file is a pure function-definition file, so a guarded include_once is
     * safe.  Outside FA (no $path_to_root) this is a no-op and every lookup
     * simply returns "no contact".
     *
     * @return void
     */
    private function ensureNativeFunctions(): void
    {
        if (function_exists('get_crm_person')) {
            return;
        }

        if (self::$includeAttempted) {
            return;
        }
        self::$includeAttempted = true;

        $pathToRoot = isset($GLOBALS['path_to_root']) ? (string) $GLOBALS['path_to_root'] : '';
        if ($pathToRoot === '') {
            return;
        }

        $file = $pathToRoot . '/includes/db/crm_contacts_db.inc';
        if (is_file($file)) {
            include_once $file;
        }
    }
}

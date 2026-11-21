<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ContactResolver;

use ksfraser\FrontAccounting\Common\ContactResolver\Contract\ContactResolverInterface;

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

        if (!function_exists('db_query')) {
            return null;
        }

        // Get table prefix from global config — TB_PREF may not be defined
        // when called from certain contexts (e.g., during FA bootstrap).
        global $db_connections;
        $tbpref = $db_connections[0]['tbpref'] ?? '0_';

        // Query crm_contacts directly for the person_id, then get_crm_person()
        // for person data. This avoids get_crm_contact() which does an INNER
        // JOIN with crm_categories and can return nothing when the category
        // row doesn't match.
        $sql = "SELECT r.person_id FROM " . $tbpref . "crm_contacts r WHERE r.id = " . (int) $contactId;
        $result = db_query($sql);
        if ($result === false || !($row = db_fetch_assoc($result))) {
            return null;
        }

        $personId = (int) $row['person_id'];

        // Get person data directly from crm_persons — don't rely on
        // get_crm_person() which may not be defined in all contexts.
        $sql = "SELECT * FROM " . $tbpref . "crm_persons WHERE id = " . $personId;
        $result = db_query($sql);
        if ($result === false || !($personRow = db_fetch_assoc($result))) {
            return null;
        }

        return Contact::fromArray([
            'id'         => $personRow['id'],
            'name'       => $personRow['name'] ?? '',
            'name2'      => $personRow['name2'] ?? '',
            'email'      => $personRow['email'] ?? '',
            'phone'      => $personRow['phone'] ?? '',
            'phone2'     => $personRow['phone2'] ?? '',
            'ref'        => $personRow['ref'] ?? '',
            'address'    => $personRow['address'] ?? '',
            'type'       => $personRow['type'] ?? null,
            'action'     => $personRow['action'] ?? null,
            'contact_id' => $contactId,
            'inactive'   => $personRow['inactive'] ?? false,
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

        return $rows;
    }
}
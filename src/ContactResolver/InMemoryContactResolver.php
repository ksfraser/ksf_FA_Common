<?php
/**
 * InMemoryContactResolver — the non-FA implementation of the shared contact
 * resolver.
 *
 * Same shape and the same discipline as the ksfraser\CommonDb precedent
 * (PdoDbAdapter is the portable side of DbConnectionInterface, FaDbAdapter
 * the mandatory FA runtime side): this implementation is for unit tests, CLI
 * tools, and non-FA platforms that keep their directory in process.  It has
 * no FA coupling at all — no FA function, no FA table, no FA constant.
 *
 * It is also the reference semantics for the contract: the lookups below are
 * exactly the ones FaContactResolver offers, expressed over plain arrays.
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
 * Array-backed contact resolver.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver
 * @since   2.2.0
 */
final class InMemoryContactResolver implements ContactResolverInterface
{
    /** @var array<int, array> Person id => person row */
    private $persons = [];

    /** @var array<int, array> Contact relation id => relation row */
    private $contacts = [];

    /** @var array<string, int> "type/action" => contact category id */
    private $categories = [];

    /** @var int Next contact relation id */
    private $nextContactId = 1;

    /** @var int Next contact category id */
    private $nextCategoryId = 1;

    /**
     * Add (or replace) a person.
     *
     * @param array $row Person row; 'id' is required, the rest are optional
     *                   (name, name2, email, phone, ref, address, inactive)
     *
     * @return Contact The stored person
     */
    public function addPerson(array $row): Contact
    {
        $contact = Contact::fromArray($row);
        $this->persons[$contact->getId()] = $row;

        return $contact;
    }

    /**
     * Attach a person to an entity, creating a contact relation.
     *
     * @param int    $personId Person id
     * @param string $type     Contact type ('customer', 'supplier', …)
     * @param string $action   Contact action ('general', 'delivery', …)
     * @param string $entityId The related entity's id
     *
     * @return Contact The stored contact
     */
    public function addContact(int $personId, string $type, string $action, string $entityId): Contact
    {
        $contactId = $this->nextContactId++;
        $person    = $this->persons[$personId] ?? ['id' => $personId];

        $row = $person + [
            'id'          => $personId,
            'type'        => $type,
            'action'      => $action,
            'entity_id'   => $entityId,
            'contact_id'  => $contactId,
            'category_id' => $this->categoryId($type, $action),
        ];

        $this->contacts[$contactId] = $row;

        return Contact::fromArray($row);
    }

    /**
     * The contact category id of a type/action pair (assigned on first use).
     *
     * @param string $type   Contact type
     * @param string $action Contact action
     *
     * @return int
     */
    private function categoryId(string $type, string $action): int
    {
        $key = $type . '/' . $action;
        if (!isset($this->categories[$key])) {
            $this->categories[$key] = $this->nextCategoryId++;
        }

        return $this->categories[$key];
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
        if (!isset($this->persons[$personId])) {
            return null;
        }

        return Contact::fromArray($this->persons[$personId]);
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
        $email = trim($email);
        if ($email === '') {
            return [];
        }

        $needle = strtolower($email);
        $found  = [];

        foreach ($this->persons as $row) {
            if (strtolower((string) ($row['email'] ?? '')) !== $needle) {
                continue;
            }
            $contact = Contact::fromArray($row);
            if (!$includeInactive && $contact->isInactive()) {
                continue;
            }
            $found[] = $contact;
        }

        return $found;
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
     * @param string      $type     Contact type
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

        $found = [];
        foreach ($this->contacts as $row) {
            if ((string) ($row['type'] ?? '') !== $type) {
                continue;
            }
            if ((string) ($row['entity_id'] ?? '') !== $entityId) {
                continue;
            }
            if ($action !== null && (string) ($row['action'] ?? '') !== $action) {
                continue;
            }
            $found[] = Contact::fromArray($row);
        }

        return $found;
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
        if (!isset($this->contacts[$contactId])) {
            return null;
        }

        return Contact::fromArray($this->contacts[$contactId]);
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
        $categories = [];
        foreach ($this->contacts as $row) {
            if ((int) ($row['id'] ?? 0) !== $personId) {
                continue;
            }
            $category = $row['category_id'] ?? null;
            if ($category !== null && is_numeric($category)) {
                $categories[] = (int) $category;
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
        $query = trim($query);
        if ($query === '' || $limit <= 0) {
            return [];
        }

        $needle = strtolower($query);
        $found  = [];

        foreach ($this->persons as $row) {
            $fields = ['name', 'name2', 'email', 'phone', 'phone2', 'ref'];
            $hit    = false;
            foreach ($fields as $field) {
                $value = (string) ($row[$field] ?? '');
                if ($value !== '' && strpos(strtolower($value), $needle) !== false) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            $contact = Contact::fromArray($row);
            if (!$includeInactive && $contact->isInactive()) {
                continue;
            }
            $found[] = $contact;
            if (\count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }
}

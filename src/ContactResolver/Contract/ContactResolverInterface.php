<?php
/**
 * ContactResolverInterface — the shared contact/person resolution contract.
 *
 * THE RULE (AGENTS_ARCH.md §12.2, owner decision 2026-10): a module must use
 * FA core's own data through FA core's own functions rather than
 * re-implementing the storage.  A module that needs a contact goes through
 * THIS contract; it must not query a contact table itself.
 *
 * One contact registry exists: FA core's `0_crm_persons` (the person) and
 * `0_crm_contacts` (the person-to-entity relation).  Our own
 * `0_ksf_crm_contacts` table is DEPRECATED and must not be used, migrated to,
 * or replaced by another `0_ksf_*` counterpart.
 *
 * SHAPE (same discipline as ksfraser\CommonDb\Contract\DbConnectionInterface):
 *   - this Contract has NO FA coupling — it names no FA function and no FA
 *     table, so a non-FA platform (WordPress, Nextcloud, standalone CLI) can
 *     implement it against its own directory;
 *   - FaContactResolver is the implementation used at FA runtime and it calls
 *     FA's NATIVE functions only (get_crm_person, get_crm_persons,
 *     get_crm_contact, get_person_contacts) — never raw SQL against an FA
 *     core table;
 *   - InMemoryContactResolver is the non-FA implementation, used by unit
 *     tests, CLI tools and embedded use.
 * DI selects the implementation; a shared seam is NEVER gated on another
 * module being activated.
 *
 * @BABOK Related: TBD — no requirement id for the shared contact resolver
 *                 exists in this repo's ProjectDcs (doc/ProjectDocuments/
 *                 ProjectDcs is empty); the decision and the native function
 *                 list are recorded in ksf_Architecture_Docs AGENTS_ARCH.md
 *                 §12.2. The closest estate requirement is FR-EM-001-03
 *                 (ksf_Architecture_Docs/Requirements/), which specifies the
 *                 same contact-resolution semantics.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver\Contract
 * @since   2.2.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ContactResolver\Contract;

use ksfraser\FrontAccounting\Common\ContactResolver\Contact;

/**
 * Resolve contacts (FA persons) and their relations.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver\Contract
 * @since   2.2.0
 */
interface ContactResolverInterface
{
    /**
     * Resolve one person (contact) by its person id.
     *
     * @param int $personId Person id (FA: `0_crm_persons`.`id`)
     *
     * @return Contact|null The person, or null when there is no such person
     */
    public function findById(int $personId): ?Contact;

    /**
     * Resolve every person whose email address matches.
     *
     * More than one match is normal (a shared household address, the same
     * person linked as both customer and supplier) — the caller decides.  See
     * FR-EM-001-03 §5.1: a retired (inactive) person is not a match unless
     * the caller asks for inactive people explicitly.
     *
     * @param string $email           Email address to match
     * @param bool   $includeInactive Include people flagged inactive
     *
     * @return Contact[] Matching people (empty when none)
     */
    public function findByEmail(string $email, bool $includeInactive = false): array;

    /**
     * Resolve the contacts of a debtor (an FA customer).
     *
     * @param string      $debtorNo Debtor id (FA: `0_debtors_master`.`debtor_no`)
     * @param string|null $action   Optional contact action to narrow to
     *                               (e.g. 'general'); null = every action
     *
     * @return Contact[] The customer's contacts (empty when none)
     */
    public function findByCustomer(string $debtorNo, ?string $action = null): array;

    /**
     * Resolve the contacts of any entity.
     *
     * @param string      $type     Contact type (FA: 'customer', 'supplier',
     *                               'cust_branch', …)
     * @param string      $entityId The related entity's id
     * @param string|null $action   Optional contact action to narrow to
     *
     * @return Contact[] The entity's contacts (empty when none)
     */
    public function findByEntity(string $type, string $entityId, ?string $action = null): array;

    /**
     * Resolve one contact relation row.
     *
     * The returned Contact carries BOTH ids: the person id (`getId()`) and
     * the contact-relation id (`getContactId()`).  They are different numbers
     * in different tables and must not be confused.
     *
     * @param int $contactId Contact relation id (FA: `0_crm_contacts`.`id`)
     *
     * @return Contact|null The contact, or null when there is no such contact
     */
    public function findContactById(int $contactId): ?Contact;

    /**
     * Return the contact CATEGORY ids of a person.
     *
     * These are `0_crm_categories`.`id` values, NOT contact-relation ids.
     *
     * @param int $personId Person id
     *
     * @return int[] Category ids (empty when the person has no contacts)
     */
    public function listContactCategoryIds(int $personId): array;

    /**
     * Search people by name, email or phone.
     *
     * @param string $query          Search term
     * @param int    $limit          Maximum rows to return
     * @param bool   $includeInactive Include people flagged inactive
     *
     * @return Contact[] Matching people (empty when none)
     */
    public function search(string $query, int $limit = 20, bool $includeInactive = false): array;
}

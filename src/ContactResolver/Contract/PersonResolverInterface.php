<?php
/**
 * PersonResolverInterface — person-only resolution contract.
 *
 * A Person is a row in FA core's `0_crm_persons` table — a human being with
 * a name, email, phone, etc.  A Contact (see ContactResolverInterface) is a
 * Person plus a type/action/entity relation in `0_crm_contacts`.
 *
 * This interface covers person-only operations.  It has NO FA coupling — it
 * names no FA function and no FA table, so a non-FA platform can implement
 * it against its own directory.  FaPersonResolver is the FA implementation
 * and calls FA's native functions only.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver\Contract
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ContactResolver\Contract;

use ksfraser\FrontAccounting\Common\ContactResolver\Contact;

/**
 * Resolve a Person — crm_persons row data only, no type/action context.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver\Contract
 * @since   2.3.0
 */
interface PersonResolverInterface
{
    /**
     * Resolve one person by its person id.
     *
     * @param int $personId Person id (FA: `0_crm_persons`.`id`)
     *
     * @return Contact|null The person, or null when there is no such person
     */
    public function findById(int $personId): ?Contact;

    /**
     * Resolve every person whose email address matches.
     *
     * @param string $email           Email address to match
     * @param bool   $includeInactive Include people flagged inactive
     *
     * @return Contact[] Matching people (empty when none)
     */
    public function findByEmail(string $email, bool $includeInactive = false): array;

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

    /**
     * Return the contact CATEGORY ids of a person.
     *
     * @param int $personId Person id
     *
     * @return int[] Category ids (empty when the person has no contacts)
     */
    public function listContactCategoryIds(int $personId): array;
}

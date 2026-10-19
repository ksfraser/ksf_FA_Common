<?php
/**
 * ContactResolverHookContract — the hook names and payload keys of the shared
 * contact resolver.
 *
 * House style: a final, constant-only class in the same style as
 * PreferenceHookContract (see src/Preference/PreferenceHookContract.php).
 * The contract itself is the interface; this class only names the wire.
 *
 * A platform that is NOT FrontAccounting (WordPress, Nextcloud, a standalone
 * CLI) answers the hook instead of implementing the FA adapter, so consumers
 * keep calling one API:
 *
 *   $data = [
 *       'operation' => 'by_email',
 *       'arguments' => ['email' => 'a@b.c', 'include_inactive' => false],
 *       'result'    => null,   // set this to answer
 *       'handled'   => false,  // set this to true to answer
 *   ];
 *   hook_invoke_all(ContactResolverHookContract::HOOK_RESOLVE, $data);
 *
 * A listener that does not understand the operation leaves 'handled' false;
 * the resolver then falls back to its own implementation.  A listener's
 * ABSENCE is a normal state, never an error: a shared seam is never gated on
 * another module being activated.
 *
 * @BABOK Related: TBD — no requirement id for the shared contact resolver
 *                 exists in this repo's ProjectDcs (doc/ProjectDocuments/
 *                 ProjectDcs is empty); the decision and the native function
 *                 list are recorded in ksf_Architecture_Docs AGENTS_ARCH.md
 *                 §12.2. The closest estate requirement is FR-EM-001-03
 *                 (ksf_Architecture_Docs/Requirements/).
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver\Contract
 * @since   2.2.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ContactResolver\Contract;

/**
 * Hook names and payload keys for the contact resolver.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver\Contract
 * @since   2.2.0
 */
final class ContactResolverHookContract
{
    /** The single hook a non-FA platform answers. */
    public const HOOK_RESOLVE = 'ksf_contact_resolve';

    /** Payload key: the lookup being asked for (one of the OP_* constants). */
    public const KEY_OPERATION = 'operation';

    /** Payload key: the lookup's arguments, as an associative array. */
    public const KEY_ARGUMENTS = 'arguments';

    /** Payload key: the answer. A list of contact arrays (or ids, for the
     *  contact-category operation). A listener sets this AND 'handled'. */
    public const KEY_RESULT = 'result';

    /** Payload key: set to true by a listener that answered the question. */
    public const KEY_HANDLED = 'handled';

    /** Operation: person by id. Arguments: person_id. */
    public const OP_BY_ID = 'by_id';

    /** Operation: persons by email. Arguments: email, include_inactive. */
    public const OP_BY_EMAIL = 'by_email';

    /** Operation: a debtor's contacts. Arguments: debtor_no, action. */
    public const OP_BY_CUSTOMER = 'by_customer';

    /** Operation: an entity's contacts. Arguments: type, entity_id, action. */
    public const OP_BY_ENTITY = 'by_entity';

    /** Operation: one contact relation. Arguments: contact_id. */
    public const OP_BY_CONTACT_ID = 'by_contact_id';

    /** Operation: a person's contact category ids. Arguments: person_id. */
    public const OP_CONTACT_CATEGORY_IDS = 'contact_category_ids';

    /** Operation: people search. Arguments: query, limit, include_inactive. */
    public const OP_SEARCH = 'search';

    /**
     * Not instantiable.
     */
    private function __construct()
    {
    }
}

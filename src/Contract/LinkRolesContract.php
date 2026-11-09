<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Contract;

/**
 * LinkRoles contract — modules that own entity types declare the link roles
 * valid for each type.
 *
 * Every ksf_FA_* module that registers entity types should implement this
 * contract (typically via the LinkRolesTrait) so consumers can discover the
 * valid roles for a given entity type without knowing the module's internals.
 *
 * Roles describe WHY two records are related (e.g. 'regarding', 'with',
 * 'invoice_contact', 'reference'). Each module owns the roles for the entity
 * types it registers — Calendar must not declare roles for Invoice, Invoice
 * must not declare roles for Meeting, etc.
 *
 * @since 1.1.0
 */
interface LinkRolesContract
{
    /**
     * Link roles valid for one entity type.
     *
     * @param string $entity_type Registry key
     * @return array<string, string> role key => human label
     * @since 1.1.0
     */
    public function linkRolesFor(string $entity_type): array;

    /**
     * Default link roles when a type has no declared roles.
     *
     * @return array<string, string> role key => human label
     * @since 1.1.0
     */
    public function defaultLinkRoles(): array;
}

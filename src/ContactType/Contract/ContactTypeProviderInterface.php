<?php
/**
 * ContactTypeProviderInterface
 *
 * Any module that provides contact types implements this interface.  The
 * ContactTypeRegistry collects all types via activation-time registration
 * (not runtime hooks), persisting them in the `ksf_contact_types` table.
 *
 * This interface is primarily useful for DI containers and service-level
 * type discovery.  The canonical registration path is:
 *   activate_extension() → ContactTypeRegistry::registerTypes()
 *
 * @package KsfCommon\ContactType\Contract
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ContactType\Contract;

use ksfraser\FrontAccounting\Common\ContactType\ContactType;

interface ContactTypeProviderInterface
{
    /**
     * Return the contact types this module provides.
     *
     * Called during module activation to populate the `ksf_contact_types`
     * table.  Types with the same name as an already-registered type are
     * silently ignored (INSERT IGNORE — first wins).
     *
     * @return ContactType[]
     */
    public function getContactTypes(): array;
}

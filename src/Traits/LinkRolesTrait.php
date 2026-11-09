<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Traits;

use ksfraser\FrontAccounting\Common\Contract\LinkRolesContract;

/**
 * LinkRoles trait — provides linkRolesFor() and defaultLinkRoles() for any
 * class that declares its roles via the protected linkRoles() method.
 *
 * Modules that own entity types implement linkRoles() returning
 * [type => [role => label, ...]] and use this trait to satisfy the
 * LinkRolesContract.
 *
 * Example:
 *   class MyEntityRegistry extends CommonEntityRegistry implements LinkRolesContract
 *   {
 *       use LinkRolesTrait;
 *
 *       protected function linkRoles(): array
 *       {
 *           $roles = [
 *               'my_type' => ['source_my_type' => 'Source My Type'],
 *           ];
 *           return array_merge(parent::linkRoles(), $roles);
 *       }
 *   }
 *
 * @since 1.1.0
 */
trait LinkRolesTrait
{
    /**
     * Entity type => [role => label]. Implemented by the using class.
     *
     * @return array<string, array<string, string>>
     */
    abstract protected function linkRoles(): array;

    /**
     * {@inheritdoc}
     */
    public function linkRolesFor(string $entity_type): array
    {
        $roles = $this->linkRoles();
        return isset($roles[$entity_type]) ? $roles[$entity_type] : array();
    }

    /**
     * {@inheritdoc}
     */
    public function defaultLinkRoles(): array
    {
        return array('reference' => 'Reference');
    }
}

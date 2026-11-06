<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Repository;

/**
 * Base entity registry for cross-module entity type definitions.
 *
 * Provides common entity types (customer, supplier, etc.) that can be
 * extended by modules to add their own entity types.
 *
 * @since 1.0.0
 */
class EntityRegistry
{
    /**
     * Build the base entity type registry.
     *
     * @return array<string, array>
     */
    protected function buildRegistry(): array
    {
        return array(
            'customer' => array(
                'title'    => 'Customer',
                'table'    => 'debtors_master',
                'pk'       => 'debtor_no',
                'label'    => 'name',
                'module'   => '',
                'picker'   => true,
                'multiple' => true,
            ),
            'supplier' => array(
                'title'    => 'Supplier',
                'table'    => 'suppliers',
                'pk'       => 'supplier_id',
                'label'    => 'supplier_name',
                'module'   => '',
                'picker'   => true,
                'multiple' => true,
            ),
            'item' => array(
                'title'    => 'Item',
                'table'    => 'stock_master',
                'pk'       => 'stock_id',
                'label'    => 'description',
                'module'   => '',
                'picker'   => true,
                'multiple' => true,
            ),
            'sales_order' => array(
                'title'    => 'Sales Order',
                'table'    => 'sales_orders',
                'pk'       => 'order_no',
                'label'    => 'reference',
                'module'   => '',
                'picker'   => false,
                'multiple' => true,
            ),
            'purchase_order' => array(
                'title'    => 'Purchase Order',
                'table'    => 'purch_orders',
                'pk'       => 'order_no',
                'label'    => 'reference',
                'module'   => '',
                'picker'   => false,
                'multiple' => true,
            ),
        );
    }

    /**
     * Get base link roles.
     *
     * @return array<string, array>
     */
    protected function linkRoles(): array
    {
        return array(
            'customer' => array(
                'source_customer' => 'Source Customer',
                'regarding' => 'Regarding',
                'reference' => 'Reference',
            ),
            'supplier' => array(
                'source_supplier' => 'Source Supplier',
                'reference' => 'Reference',
            ),
            'item' => array(
                'source_item' => 'Source Item',
                'reference' => 'Reference',
            ),
            'sales_order' => array(
                'source_order' => 'Source Order',
                'reference' => 'Reference',
            ),
            'purchase_order' => array(
                'source_po' => 'Source PO',
                'reference' => 'Reference',
            ),
        );
    }

    /**
     * Get note types (empty base, extended by modules).
     *
     * @return array<string, string>
     */
    public function noteTypes(): array
    {
        return array();
    }

    /**
     * Get the full entity type registry.
     *
     * @return array<string, array>
     */
    public function all(): array
    {
        return $this->buildRegistry();
    }

    /**
     * Get one entity type definition.
     *
     * @param string $entity_type Registry key
     * @return array|null Definition or null when unknown
     */
    public function get(string $entity_type): ?array
    {
        $types = $this->buildRegistry();
        return isset($types[$entity_type]) ? $types[$entity_type] : null;
    }

    /**
     * Get entity types that can be offered in pickers (picker => true).
     *
     * @return array<string, array>
     */
    public function pickable(): array
    {
        $types = $this->buildRegistry();
        $pickable = array();
        foreach ($types as $key => $def) {
            if (!empty($def['picker'])) {
                $pickable[$key] = $def;
            }
        }
        return $pickable;
    }

    /**
     * Human labels for registered types (type => title).
     *
     * @return array<string, string>
     */
    public function typeLabels(): array
    {
        $types = $this->buildRegistry();
        $labels = array();
        foreach ($types as $key => $def) {
            $labels[$key] = $def['title'];
        }
        return $labels;
    }

    /**
     * Link roles valid for one entity type.
     *
     * @param string $entity_type Registry key
     * @return array<string, string>
     */
    public function linkRolesFor(string $entity_type): array
    {
        $roles = $this->linkRoles();
        error_log("DEBUG linkRolesFor(" . $entity_type . ") = " . json_encode($roles[$entity_type] ?? []));
        return isset($roles[$entity_type]) ? $roles[$entity_type] : array();
    }

    /**
     * Default link roles when type has no declared roles.
     *
     * @return array<string, string>
     */
    public function defaultLinkRoles(): array
    {
        return array('reference' => 'Reference');
    }
}

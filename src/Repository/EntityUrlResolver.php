<?php
/**
 * EntityUrlResolver — the FA implementation of EntityUrlResolverInterface.
 *
 * Carries the canonical URL patterns for FA core entities (customer, supplier,
 * item, sales invoice, purchase order, payment, contact, …). Modules that need
 * custom URL patterns register their own implementation via DI or answer the
 * `ksf_entity_url_resolve` hook.
 *
 * URLs are web-root-relative (start with '/') so they resolve correctly
 * regardless of which module's page is currently rendering.
 *
 * @package ksfraser\FrontAccounting\Common\Repository
 * @since   2.4.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Repository;

use ksfraser\FrontAccounting\Common\Contract\EntityUrlResolverInterface;

/**
 * Resolves entity record URLs using canonical FA URL patterns.
 *
 * URL patterns use `{id}` as a placeholder for the entity primary key.
 * Modules can register additional patterns via `registerUrlPattern()`.
 *
 * @package ksfraser\FrontAccounting\Common\Repository
 * @since   2.4.0
 */
class EntityUrlResolver implements EntityUrlResolverInterface
{
    /**
     * @var array<string, string> Entity type key => URL pattern with {id}
     */
    private $urlPatterns = array(
        'customer'    => '/sales/manage/customers.php?debtor_no={id}',
        'supplier'    => '/purchasing/manage/suppliers.php?supplier_id={id}',
        'item'        => '/items/manage/items.php?stock_id={id}',
        'invoice'     => '/sales/view/view_invoice.php?trans_no={id}&trans_type=10',
        'sales_order' => '/sales/view/view_sales_order.php?trans_no={id}&trans_type=20',
        'purchase_order' => '/purchasing/view/view_po.php?trans_no={id}',
        'payment'     => '/bank/bank_payment_view.php?trans_no={id}',
        'receipt'     => '/sales/view/view_receipt.php?trans_no={id}',
        'credit_note' => '/sales/view/view_credit.php?trans_no={id}&trans_type=21',
        'contact'     => '/crm/crm_contacts.php?contact_id={id}',
        'meeting'     => '/crm/meeting_view.php?id={id}',
        'opportunity' => '/crm/opportunity_view.php?id={id}',
    );

    /**
     * Resolve the web-root-relative URL for viewing one entity record.
     *
     * @param string $entityType Entity type key
     * @param string|int $entityId Primary key value
     *
     * @return string URL path starting with '/', or '#' when no pattern
     *         is registered for the entity type
     * @since 2.4.0
     */
    public function resolveUrl(string $entityType, $entityId): string
    {
        $pattern = $this->urlPatterns[$entityType] ?? null;
        if ($pattern === null) {
            return '#';
        }

        return str_replace('{id}', (string) $entityId, $pattern);
    }

    /**
     * Register (or override) a URL pattern for an entity type.
     *
     * @param string $entityType Entity type key
     * @param string $pattern URL pattern with {id} placeholder
     * @return void
     * @since 2.4.0
     */
    public function registerUrlPattern(string $entityType, string $pattern): void
    {
        $this->urlPatterns[$entityType] = $pattern;
    }

    /**
     * Get all registered URL patterns.
     *
     * @return array<string, string> Entity type key => URL pattern
     * @since 2.4.0
     */
    public function getUrlPatterns(): array
    {
        return $this->urlPatterns;
    }
}

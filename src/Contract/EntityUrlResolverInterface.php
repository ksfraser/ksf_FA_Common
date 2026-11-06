<?php
/**
 * EntityUrlResolverInterface — shared URL resolution contract.
 *
 * Resolves absolute (web-root-relative) URLs for entity records so that
 * related-record links, audit trails, notifications, and cross-module
 * references all point at the correct FA native screens regardless of which
 * module's page is currently rendering.
 *
 * This interface has NO FA coupling — it names no FA function and no FA
 * table, so a non-FA platform (WordPress, Nextcloud, standalone CLI) can
 * implement it against its own routing.
 *
 * The default implementation `EntityUrlResolver` (ksf_FA_Common) carries the
 * canonical URL patterns for FA core entities (customer, supplier, item,
 * sales invoice, purchase order, payment, contact, …). Modules that need
 * custom URL patterns register their own implementation via DI or answer the
 * `ksf_entity_url_resolve` hook.
 *
 * @package ksfraser\FrontAccounting\Common\Contract
 * @since   2.4.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Contract;

/**
 * Resolve entity record URLs.
 *
 * @package ksfraser\FrontAccounting\Common\Contract
 * @since   2.4.0
 */
interface EntityUrlResolverInterface
{
    /**
     * Resolve the web-root-relative URL for viewing one entity record.
     *
     * The returned URL is relative to FA's web root (starts with '/') so that
     * it works regardless of which module's page is currently rendering.
     *
     * @param string $entityType Entity type key (e.g. 'customer', 'invoice',
     *        'contact', 'supplier', 'item', 'payment')
     * @param string|int $entityId Primary key value for the entity record
     *
     * @return string URL path starting with '/', or '#' when no URL pattern
     *         is registered for the entity type
     * @since 2.4.0
     */
    public function resolveUrl(string $entityType, $entityId): string;
}

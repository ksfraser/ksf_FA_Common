# WooCommerce & Square API Coverage — Cross-Cutting Summary

**Date**: 2026-08-09
**Purpose**: Cross-module summary of the field-level API coverage research and gap analysis
for the event-driven product sync (ksf_FA_Common → ksf_FA_Square + ksf_FA_Woocommerce).
Companion to the per-repo matrices:
- `ksf_FA_Square/docs/api_research/square_gap_analysis.md` (131 Square gaps)
- `ksf_FA_Woocommerce/docs/api_research/woo_gap_analysis.md` (56 Woo gaps)

## Source of truth

- **Square**: canonical Square Connect OpenAPI spec (downloaded 2026-08-01 from the
  official spec distribution). Field definitions extracted programmatically, so this
  reflects the current API, not docs drift.
- **WooCommerce**: REST API v3 schemas extracted from the actual WooCommerce controller
  source (`class-wc-rest-products-controller.php`, `-product-variations-`, `-product-attributes-`,
  `-attribute-terms-`, `-product-categories-`, `-product-tags-`, `-shipping-classes-`,
  `-taxes-`, `class-wc-rest-crud-controller.php`), cross-checked against the published docs.

## Headline numbers

| Side | Writable fields covered by research | Gaps | Direct bugs |
|---|---|---|---|
| Square (item sync path) | ~131 fields enumerated | 131 uncovered | 0 (2 latent SDK drift hazards) |
| WooCommerce (product sync path) | ~90 fields enumerated | 56 uncovered | 4 |

## Immediate bugs to fix (impact > effort)

1. **Square tax = "0"** — `export.php:360` and `ItemEventSyncService:121` hardcode
   `tax_data.percentage = "0"`; the FA sales-tax rate is never looked up. Items ship
   tax-free.
2. **Woo `in_stock` no-op** — V3 write handlers ignore `in_stock`; products with qty 0
   remain `instock` and can be oversold. Must send `stock_status=outofstock`.
3. **Woo variation `stock_quantity` dropped** — sent without `manage_stock` so the API
   discards it.
4. **Woo `weight_unit` / `dimensions.unit`** — written as top-level/schema-invalid
   fields; units are store-level in V3.

## Top feature gaps by sync-completeness impact

| Gap | Home module(s) |
|---|---|
| Square `location_overrides` (per-location price/inventory) | Sales (sales-type ↔ Square-location mapping) |
| Square availability (`channels`, category `online_visibility`) — UI "Available Online" toggle is dead | FA_ProductAttributes |
| Square `upc` (barcode currently sent as `sku`) | FA_ProductAttributes (item_codes) |
| Square `measurement_unit_id` (stock_master.units never mapped) | FA_ProductAttributes |
| Square `description_html` / FA long_description never synced | FA_ProductAttributes |
| Square `is_archived` (inactive items skipped, not archived) | FA_ProductAttributes |
| Square modifiers / modifier lists | FA_ProductAttributes |
| Square discounts + pricing rules + product sets | Sales (discounts/promotions) |
| Square `present_at_all_locations` / `present_at_location_ids` | FA_ProductAttributes |
| Woo `categories` never attached (CategoryExporter only makes terms) | ProductExportService / FA_ProductAttributes |
| Woo `sale_price` + `date_on_sale_from/to` parsed but never emitted | Sales (sale pricing) |
| Woo `stock_status` never set (see bug #2) | ProductExportService |
| Woo `tax_class` / `tax_status` / `/taxes` not synced | Sales (tax rates) |
| Woo global attribute registry (custom per-product attributes instead of reused `id`) | FA_ProductAttributes |
| Woo `default_attributes` dead code | ProductExportService |
| Woo `tags` dead code (V1 shape) | ProductExportService |
| Woo `global_unique_id` (GTIN/UPC/EAN via unreliable meta) | FA_ProductAttributes |
| Woo `shipping_class` hardcoded `'hazardous'` | Sales (shipping classes) |

## Latent SDK drift hazards (Square)

- `available_for_online` / `available_for_pickup` / `available_electronically` are
  **removed from the current spec** but the pinned `square/square 40.0.0` SDK still
  exposes the setters — a naive fix of the dead "Available Online" toggle would write a
  removed field. Correct target: `channels` + category `online_visibility`.
- `ItemVariationLocationOverrides.sold_out`, Location `country`/`currency`/`logo_url`/
  `tax_ids` are readOnly yet SDK-settable — must not be written when adding location
  overrides or Location-write features.

## Recommended implementation order

1. **Bug fixes** (independent, low risk): Square tax lookup; Woo stock_status /
   manage_stock / units.
2. **Woo categories + sale pricing + tax mapping** (reuses data already in FA).
3. **Square** `upc`, `measurement_unit_id`, `description_html`, `channels`/online
   availability — needs FA_ProductAttributes columns.
4. **Woo global attributes / default_attributes / tags** — needs FA_ProductAttributes
   registry alignment.
5. **Deferred features** (need new FA-side modeling): Square location_overrides,
   modifiers, discounts/pricing rules, Woo shipping classes, per-variation weight/shipping.

Follow the shared event contract: `hook_invoke_all('item_created'|'item_updated', $data)`
broadcast by `ItemChangeWatcher` (see `src/ItemEvents/`), consumers re-fetch via their own
DAO. Preserve the user-approved Square `CatalogExporter` push path.

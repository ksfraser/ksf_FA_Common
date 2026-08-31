# ksf_FA_Common Class-Loading Architecture (superseded 2026-08-30)

> Original note: 2026-08-29, about the "module directory canonical source"
> model. **Superseded 2026-08-30** by the pure-package cutover below. Keep this
> to record *why* the old model was abandoned so the debugging trail is not lost.

## The one-line rule (still true)

**Every class may be loaded by exactly ONE mechanism per process. Never
path-include (`require_once`) a class file that is autoloadable, and never give
two autoloaders the same namespace prefix.**

## Current model (as of 2026-08-30)

ksf_FA_Common is a **pure Composer/Packagist package** (`ksfraser/ksf-fa-common`,
`type: library`). Every consumer's vendored copy — loaded through that module's
own `vendor/autoload.php` — is the single load source for:

- `ksfraser\FrontAccounting\Common\`        → package `src/`
- `Ksfraser\Frontaccounting\HTML\`          → package `src/HTML/`
- legacy `KsfCommon\*` aliases               → package `compat.php`
  (`composer.json` `files`; `class_alias` to the canonical classes)

### The module directory is a no-op shell

The deployed FA module (the directory under `fa_modules/`) is kept so the
module can remain installed/activated without side effects. `hooks.php` keeps
the class + `install/activate/deactivate_extension()` as **no-ops** and the
`install_access()` security sections. `install_schema()`, `src/autoload.php`
(**deleted**), the loader constant `KSF_FA_COMMON_LOADER_REGISTERED`,
`register_default_types()`, and `ensure_composer_dependencies()` are gone.

Only the inter-module Item Event API methods
(`publishItemCreated/publishItemUpdated/publishItemChanged/scanItemChanges/
isItemKnown`) remain — `ksf_FA_Calendar` still invokes `scanItemChanges` via
`hook_invoke('ksf_FA_Common', …)`. The implementation classes ship in the
package and autoload from the calling module's vendor copy.

### Schema ownership moved into the package

Fresh-install tables are created on demand by the classes that own them
(CREATE TABLE IF NOT EXISTS, once per request):

| Table                         | Ensured by                                           |
|-------------------------------|------------------------------------------------------|
| `ksf_contact_types`           | `ContactType\ContactTypeRegistry::ensureTable()`     |
| `fa_job_queue`                | `Queue\JobQueue::ensureTable()`                      |
| `ksf_notifications`           | `Notification\NotificationRepository::ensureTable()` |
| `ksf_item_sync_state` + `ksf_item_event_watermark` | `ItemEvents\DbItemChangeStateStore::ensureTable()` |

`sql/install.sql` remains as a reference DDL dump only; nothing executes it.

### Contact types are owned by their natural modules

The platform seeds **zero** types. Each owning module registers during
`activate_extension()` and unregisters during `deactivate_extension()`:

| Owner            | Types                                          |
|------------------|------------------------------------------------|
| ksf_FA_RBAC      | `fa_user`                                      |
| ksf_FA_CRM       | `crm_contact`, `lead`, `opportunity`           |
| ksf_FA_Calendar  | `invitee`, `ad_hoc`                            |
| ksf_FA_HRM       | `employee`, `team`, `job_applicant`            |
| ksf_FA_Assets    | `resource`                                     |

`INSERT IGNORE` makes registration idempotent; first registration wins. An
upgrade DB retag moves pre-existing `module='ksf_FA_Common'` rows to the owning
modules (RBAC/CRM/Calendar/Assets).

### Why the old model was abandoned

Class availability must never depend on another module's activation state. The
module-canonical loader conflated **class loading** (needs to be per-process,
always-on) with **module activation** (a runtime, per-company, mutable flag).
When ksf_FA_Common was deactivated, every sibling module's classes — and plain
pages like `items.php` built on them — became unresolvable and fatal'ed instead
of degrading. Deploying as a package + no-op shell removes the coupling.

## What consumers must do

- Reference the shared classes by namespace only; never
  `require_once …/ksf_FA_Common/src/…` anything. The package autoloads from
  their own `vendor/autoload.php`.
- Guard runtime hook bodies with a `class_exists(...)` probe of
  `KsfCommon\Plugin\PluginRegistry` / `KsfCommon\Plugin\AbstractPlugin` (the
  bases of `fa-product-attributes-core`'s `TabRegistry`/tabs) and degrade to a
  `display_error()` warning instead of a fatal — see
  FA_ProductAttributes `hooks.php::ksf_fa_common_available()`.
- After a new ksf-fa-common release, `composer update ksfraser/ksf-fa-common`
  in every module that requires it:
  FA_ProductAttributes, ksf_FA_Calendar, ksf_FA_Logging, ksf_FA_Square,
  ksf_FA_SuggestedPurchaseOrder, ksf_FA_Upc2Item.

## Historical fatals note (2026-08-29)

Before this supersession, the shared namespaces were reachable through two real
paths (module dir + vendored copies) and PHP fatals'ed with
`Cannot declare class X, because the name is already in use`. Fix that day was
making the module dir canonical; that fix is now itself replaced by the pure
package model. Under opcache `class_exists(..., false)` top-of-file guards are
unreliable; the `define()` guard pattern declared inside the `if` still holds.

## Gotchas

- **opcache**: after editing any `public/*.php` or hooks file on the UAT
  container, stale bytecode may serve for `revalidate_freq`. Nudge with an
  `opcache_reset()` probe or restart the web container.
- **Never** give two composer/PSR-4 autoloaders the same prefix for a package
  that is also path-included.
- Local CLI PHP mirrors the container: `opcache.enable_cli=On`.

## Verification

- `php -l` all edited files.
- Both suites green: FA_ProductAttributes `vendor/bin/phpunit`
  (922 tests, 2020 assertions) and ksf_FA_Common
  `vendor/bin/phpunit -c phpunit.xml` (202 tests, 386 assertions).
- On UAT: hit each consumer page and FA root; expect zero
  `Cannot declare class` and no 500s.
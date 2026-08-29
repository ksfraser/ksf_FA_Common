# ksf_FA_Common Class-Loading Architecture

> Decided 2026-08-29. Why this doc exists: the weekend productionization
> (FA_ProductAttributes + Square/Woo) hit fatal class-redeclaration errors.
> This is the single-source-of-truth note on how the shared library loads, so
> that in 3 months you can re-grasp the design without the full debugging trail.

## The one-line rule

**Every class may be loaded by exactly ONE mechanism per process. Never
path-include (`require_once`) a class file that is autoloadable, and never give
two autoloaders the same namespace prefix.**

## TL;DR

- ksf_FA_Common is **both** a deployed FA module (a directory under
  `fa_modules/`) **and** a Composer package (`ksfraser/ksf-fa-common`) vendored
  into client modules (FA_ProductAttributes, ksf_FA_Upc2Item, ksf_FA_Documents).
- Since 2026-08-29 the **module directory is the single authoritative source**
  for the shared namespaces. A tiny loader, `ksf_FA_Common/src/autoload.php`,
  registers an `spl_autoload_register` callback for two prefixes:
  - `ksfraser\FrontAccounting\Common\` → `ksf_FA_Common/src/`
  - `Ksfraser\Frontaccounting\HTML\` → `ksf_FA_Common/src/HTML/`
- The vendored composer copies are now **inert** (never consulted for those
  prefixes) and should eventually be purged.

## Why the fatals happened

A class is "declared" when its file executes. PHP fatals with
`Cannot declare class X, because the name is already in use` when the same real
class name is executed more than once in one process. That happened because the
shared namespaces were reachable through **two real paths simultaneously**:

1. the module directory, pulled in by sibling modules via
   `require_once dirname(__DIR__) . '/ksf_FA_Common/src/…'` from their hooks
   (HRM, RBAC, Mail, Calendar, DataIntegrity, SuggestedPurchaseOrder do this),
   and
2. each client's `vendor/ksfraser/ksf-fa-common/src/…`, loaded by Composer's
   PSR-4 autoloader (registered in `vendor/composer/autoload_psr4.php`).

`require_once` dedupes **by path only** — it does not know the class was already
loaded from a *different* path. Composer's autoloader likewise never checks
whether a class is already declared; it just resolves and `include`s. Two real
paths ⇒ two executions ⇒ redeclaration fatal.

### The tricky bit: `class_exists()` guards are a trap under opcache

The naive fix is a guard at the top of the shared files:

```php
if (class_exists(X::class, false)) { return; }   // WRONG under opcache
```

This is **unreliable**: with opcache enabled (the FA UAT container runs
`php:7.3-alpine` with opcache ON), re-including the same guarded file can still
fatal — the opcache-compiled script reports the class as already-in-use on the
second execution even though the pre-check returns false. Verified empirically;
`opcache_reset()` between requires does not help.

**Working pattern: a `define()` constant used as the guard, with the class
declared *inside* the `if`:**

```php
if (!defined('FOO_DECLARED')) {
    define('FOO_DECLARED', true);
    class Foo { /* declarations inside the guard */ }
}
```

This survives repeated requires under opcache. `ComposerDependencies.php`
(loaded as a Composer `files` entry by every consumer) uses exactly this.

## How the loader works

File: `ksf_FA_Common/src/autoload.php`

- Define-guarded by `KSF_FA_COMMON_LOADER_REGISTERED` (loading it twice is a
  no-op).
- `spl_autoload_register` a single closure mapping the two prefixes above to
  paths under the **module dir** (`__DIR__`).
- The closure defensively checks
  `class_exists/interface_exists/trait_exists($class, false)` before including,
  and uses `require_once` — belt-and-suspenders against any re-entry.
- Registered from **two** guaranteed points:
  1. `hooks_ksf_FA_Common::__construct()` — runs during FA session bootstrap
     whenever ksf_FA_Common is an **active** FA module. Because FA loads active
     module hooks in activation order, this fires **before** any sibling
     module's hooks constructor, so the module-dir loader is already registered
     (and wins) before any composer autoloader could resolve those prefixes.
     (Note: the composer autoloader is typically not yet registered at that
     instant anyway — more on ordering below.)
  2. First-line in standalone pages that boot without full FA module loading:
     FA_ProductAttributes `public/{index,brands,lifecycle-flags}.php` do
     `require_once dirname(__DIR__) . '/../ksf_FA_Common/src/autoload.php';`
     **before** their own `vendor/autoload.php`.

### Why "register first" matters

`spl_autoload_register` callbacks are tried **in registration order**; the
author first one to resolve a class wins. A composer autoloader registered
later for the same prefix will simply never be consulted for names the earlier
loader already resolved. So the module-dir loader, registered first, starves
the vendored copy for `ksfraser\FrontAccounting\Common\` — the vendored copy
becomes inert without deleting it. Same for `Ksfraser\Frontaccounting\HTML\`
(MasterSummaryTable, TabContext — these are **not** in the ksf-fa-common
package's `Common\` namespace, they live under a distinct prefix, so they get
their own entry; do not forget the trailing backslash matching).

## How other vendored packages stay safe (no module-ification needed)

Only packages that are ALSO reachable via a module-dir path were at risk —
because they had **two real paths**. For plain Composer packages
(`ksfraser/ksf-modules-dao`, `famock`, `ksf-schemamanager`) this cannot
happen: each client `require`s its own `vendor/autoload.php` once, the first
composer autoloader wins per prefix, and later registrations are never consulted
for a name the first one resolved. So multiple vendored copies of those packages
across modules cause **version skew**, not redeclarations. Keep copies in sync;
do not convert them into modules.

Rule of thumb: the ONLY packages that risk "Cannot redeclare class" are
packages that are simultaneously (a) an FA module directory and (b) a vendored
composer package — i.e. today, only ksf_FA_Common.

## What consumers must do

- **Dependents reference classes by namespace only.** Never
  `require_once '<path>/ksf_FA_Common/src/…'` a class file that the loader can
  autoload. (Existing `require_once` paths in sibling hooks were the original
  dual source and should be removed as those modules are touched.)
- **Compute the dependents' path** to the module loader from their own `__DIR__`
  so it works in both dev and UAT layouts:
  `require_once dirname(__DIR__) . '/../ksf_FA_Common/src/autoload.php';`
  (relative to a sibling module dir; for a page inside `public/` that is
  `dirname(__DIR__) . '/../ksf_FA_Common/src/autoload.php'`).
- **Activation-order guard** — since ksf_FA_Common must be an active module for
  the loader to run, dependents' `activate_extension()` must verify it:

  ```php
  if (!defined('KSF_FA_COMMON_LOADER_REGISTERED')) {
      echo '<div class="alert alert-warning">' . _('ksf_FA_Common must be installed and active before this module; activate it first.') . '</div>';
      return false;
  }
  ```

  The constant is defined exactly when ksf_FA_Common's hooks constructor ran —
  i.e. when it is an installed + active module in this FA instance — with zero
  DB/FA internals. Implemented in FA_ProductAttributes
  (`hooks.php::activate_extension`); TO-DO for ksf_FA_HRM, ksf_FA_RBAC,
  ksf_FA_Mail, ksf_FA_SuggestedPurchaseOrder, ksf_FA_Calendar, ksf_FA_Square,
  ksf_FA_Woocommerce, ksf_FA_ProjectManagement, ksf_FA_CRM,
  ksf_FA_DataIntegrity, ksf_FA_Logging.

## Endgame / where this is heading

Option A (preferred): one shared composer install at the FA webroot ("shared
libc" model) so each package exists physically once inclusion-wide — then the
module loader becomes redundant and ksf_FA_Common stops shipping as a package.

Option B: keep ksf_FA_Common module-canonical (as now) and simply **remove**
`ksfraser/ksf-fa-common` from every client `composer.json` + delete the vendored
dirs post-launch. Both work; the decision was deferred until after launch.

## Gotchas

- **opcache**: after editing any `public/*.php` or hooks file on the UAT
  container, stale bytecode may serve for `revalidate_freq`. Nudge with an
  `opcache_reset()` probe (`modules/reset.php { opcache_reset(); }`) or restart
  the web container. Remove the probe afterwards.
- **Never** give two composer/PSR-4 autoloaders the same prefix for a package
  that is also path-included — that is the exact shape that fatals.
- The `class_exists(..., false)` guard pattern above is fine *inside* an
  autoloader (it is a re-entrancy check), but **not** as the top-of-file guard
  for the class files themselves under opcache — use the `define()` pattern.
- Local CLI PHP mirrors the container: `opcache.enable_cli=On`, so guard
  behavior reproduces locally with `php -l`/direct execution.

## Verification / how to prove it clean

- `php -l` all edited files.
- On UAT, hit each consumer public page and FA root; assert zero
  `Cannot declare class` and no 500s:
  `curl -s http://localhost:8080/modules/FA_ProductAttributes/public/{index,brands,lifecycle-flags}.php`
- Reset opcache before trusting results.
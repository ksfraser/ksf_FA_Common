# AGENTS_ARCH — KSF Cross-Module Architecture & Conventions

Companion to the master `AGENTS.md`. While `AGENTS.md` records specific FA
architecture **decisions** (data-dictionary/query-builder direction, PDO rule,
hook-system reference, SQL-prefix conventions, feature root-causes), this file
captures the **shared module conventions and cross-repo engineering standards**
that every ksf FA module / library follows. If something is a convention that
applies to many modules, it lives here. Repo-specific detail lives in each
repo's `_APPENDIX` / `AGENTS_APPENDIX.md`.

Read this before creating or refactoring any ksf module.

---

## 1. PHP platform floor (canonical)

- **PHP 7.3 is the compatibility floor.** Current production runs PHP 7.3
  (Fedora 30) until a web container is stood up there. Code MUST target
  PHP 7.3: no PHP 8+ features (no `match`, no named args, no nullsafe, no
  typed properties, no union/`mixed` return types in signatures — `mixed`
  only in docblocks). The FA container runtime is 7.4; standalone/core
  libraries may differ — each repo records its own floor in `_APPENDIX`.
- `declare(strict_types=1);` at the top of every PHP file.

## 2. Core design principles

- **SOLID, DRY, SRP, DI, TDD** — single responsibility, dependencies injected
  (never hardcoded), test-first.
- **Polymorphism over conditionals** — prefer strategy/handler dispatch to
  long `if/else` chains.
- **Traits over inheritance** — shared cross-cutting behavior is composed via
  traits, not deep class inheritance.
- **Business logic + platform adapter split** — framework-agnostic business
  logic lives in a `*_Core` package (`ksfraser\<Package>\`); the FA adapter
  module (`ksf_FA_*` → `ksfraser\FrontAccounting\<Module>\`) is a thin wrapper.

## 3. Standard module layout

Every ksf FA module follows this layout:

```
<module>/
├── sql/               <table>.sql  (one file per table; retag/contact-type SQL)
├── includes/          *_db.inc     ({table}_db.inc — write_{table}(), get_{table}(), delete_{table}())
├── pages/             UI pages
├── src/               business logic (PSR-4 under ksfraser\FrontAccounting\<Module>\
├── hooks.php          hooks_<module> extends hooks
├── composer.json      PSR-4 autoload
└── ProjectDocs/       Requirements.md, RTM.md, BABOK.md, UML.md
```

Each table gets a `{table}_db.inc` gateway exposing `write_{table}()` /
`get_{table}()` / `delete_{table}()` (Table Gateway pattern).

## 4. Namespace conventions

- FA platform modules: `ksfraser\FrontAccounting\<ModuleName>\` (PSR-4, maps to `src/`).
- Framework-agnostic core / business logic: `ksfraser\<Package>\`.
- Shared libraries: `ksfraser\Exceptions\`, `ksfraser\Traits\`, `ksfraser\CommonDb\`.
- SQL tables use hardcoded `0_` company prefix (FA `db_import` does NOT resolve
  `@TB_PREF@`); PHP code uses the `TB_PREF` constant.

## 5. Coding standards

- `declare(strict_types=1);` in every file.
- Naming: `InterfaceNameInterface`, `AbstractClassName`, `ServiceNameService`,
  `ValueObjectName` (immutable VOs), class `FooException`.
- DocBlocks require `@param`, `@return`, `@throws`, `@since`.
  `@UML` / `@BABOK` annotations cross-reference `doc/ProjectDocuments/{UML,BABOK}`
  requirement files (BR-*/FR-*/UC-*/UT-*/UAT-* naming).
- Version tagging: SemVer `MAJOR.MINOR.PATCH` (`git tag -a vX.Y.Z`).
- Git: feature branches `feature/*`, `fix/*`, `refactor/*`; commits
  `type(scope): description`; merge back to the default branch.
- Never track `vendor/` or `composer.lock` (each dev/consumer runs `composer install`).

## 6. Testing / TDD

- TDD red→green→refactor; **100% code coverage** target; **skipped tests = failed**.
- PHPUnit, `Tests\Unit` namespace convention; `php -l` lint before commit.
- Business logic tested standalone (in-memory SQLite / PDO stubs); FA adapter
  code tested against namespaced stubs of the FA `db_*` functions.

## 7. Development / deployment workflow

- Develop in the **devel tree** `~/Documents/<Module>`; the UAT bind point under
  `~/ksf_Infrastructure/fa_modules/<Module>` is a **deployment bind copy only** —
  never create/edit/commit code there.
- Flow: develop → test → `php -l` → commit/branch → push → merge → deploy.
- Deploy at the bind point: `git stash -u && git pull origin <branch> && git stash pop`,
  then re-run architecture-doc hardlinks (`ln -f`) if they were clobbered.
- Container deploy: run `composer install --no-dev` (a `require-dev` that pulls
  PHP 8+ transitive deps breaks a PHP 7.x container) — pin
  `config.platform.php` to the container's PHP where needed.

### Integration-environment gotchas (ksfii_app pod, verified 2026-09)

- The dev-shell runs as **root**, so `git`/file writes inside the bind-mounted
  deploy clones leave root-owned files and rewrite mode bits on
  checkout/reset. If `git pull` at a bind point refuses with "Your local
  changes to the following files would be overwritten" while `git status --short`
  is clean, suspect in order: an `assume-unchanged` flag (`git ls-files -v`
  shows lowercase `h`), a root-owned `.git/index` (makes `git update-index`
  silently a no-op), or a 186-hardlink shared doc whose content is ahead of
  HEAD. The reliable fix is aligning the clone to origin from the deploy clone:
  `git fetch && git reset --hard origin/main`. Never hand-`chmod`/`chown`
  tracked files mid-tree; re-align instead.
- The web front-end HTML-encodes `&` in query strings mid-transit:
  `$_SERVER['REQUEST_URI']` reads e.g. `...?view=contacts&amp;filter_debtor_no=1`
  while `$_GET` still parses every param correctly (so filtering works end to
  end). Round-trips are consistent — form `action=` URLs and `Location:` headers
  carry the same encoded form and are re-decoded the same way. Therefore when
  appending a query param to `formAction()`/`REQUEST_URI` for a redirect, detect
  an existing param with `strpos($url, 'name=')` instead of splitting on raw `&`.

### ComposerDependencies — self-installing vendor on activation

Each module bundles `ComposerDependencies.php` in its **root directory**. The copy
source is the per-module template
`ksf_FA_Common/src/Utils/ComposerDependencies.template.php`: **replace the
`MODULENAME` token in the namespace with your module's short name** (e.g.
`ksfraser\FrontAccounting\HRM\Utils` for ksf_FA_HRM). This solves the
chicken-and-egg problem: vendor/ doesn't exist until composer runs, but we need
to run composer to create vendor/.

```php
// hooks.php — top of file, BEFORE any other requires
require_once __DIR__ . '/ComposerDependencies.php';
\ksfraser\FrontAccounting\HRM\Utils\ComposerDependencies::ensure(__DIR__);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
```

`ComposerDependencies::ensure($moduleDir)` checks if `vendor/autoload.php` exists. If
not, it runs `composer install --no-interaction --prefer-dist` in `$moduleDir`. FA
calls `install_extension()` before activation completes, so vendor/ is ready when
other hook methods run.

The guard is **namespace-scoped** (sentinel constant derived from `__NAMESPACE__` +
`class_exists(__NAMESPACE__, false)`). This is deliberate:
- Each module that renames `MODULENAME` gets its own class in its own namespace —
  copies can never collide, and the legacy global constant
  `KSF_FA_COMMON_COMPOSER_DEPENDENCIES_DECLARED` is NOT set for renamed copies (so a
  properly-renamed module never suppresses a sibling).
- If a module forgets to replace `MODULENAME`, all unrenamed copies collapse onto
  the placeholder namespace; only the first to load declares the class, the rest
  short-circuit — no redeclaration fatal, no clobbering. Their hooks.php calls still
  resolve since the class takes `$moduleDir` per call.
- The package's own `ksf_FA_Common/src/Utils/ComposerDependencies.php` (in the
  `Common\Utils` namespace) uses the identical guard and may also be loaded; it
  additionally defines the legacy constant for backward compatibility with older
  copies. Do NOT copy that file into a module — copy the `.template.php`.

## 8. FA module naming / security constants

- Hooks class: `hooks_ksf_FA_<ModuleName>`.
- Security section: `define('SS_ksf_FA_<ModuleName>', N << 8);`
- Security areas: `SA_ksf_FA_<ModuleName>` / `SA_<MODULE>_<ACTION>`.
- **Security-area numbering registry (single source):** Core FA uses 1–53; KSF
  modules start at 114. Current highest: `SS_GPG = 145` (`SS_DataIntegrity = 144`).
  Next available: **146**. Always take the next unused number — never reuse.

## 9. FA page security

Every direct-access module page MUST call `add_access_extensions()` (registering
its security areas) **before** `page_header()`. Missing it produces a blank
(~855-byte) page. Guard so that a user without the area is refused before any
output.

### FA UI bootstrap — `ui.inc` is NOT auto-loaded

`includes/main.inc` only pulls `ui_controls.inc` (provides `start_form`,
`end_form`, `start_table`, button helpers, ...). The rest of the FA UI layer —
`ui_lists.inc` (`customer_list`, `customer_list_row`, `combo_input`,
`array_selector`, ...), `ui_input.inc`, `ui_msgs.inc`, `ui_globals.inc`,
`ui_view.inc`, `data_checks.inc` — is loaded by `includes/ui.inc`, which every
native FA page `include_once(...)`s after `session.inc`.

App-shell module entry pages (e.g. `modules/ksf_FA_CRM/index.php`) MUST do the
same:

```php
include_once($path_to_root . "/includes/session.inc");
add_access_extensions();
include_once($path_to_root . "/includes/ui.inc"); // required for ui_lists helpers
```

Without it, tab code that calls an FA-native list/DLL helper (e.g.
`customer_list_row`) silently skips rendering that control: `start_form` /
`end_table` still work because `main.inc` loaded `ui_controls.inc`, so the page
renders normally minus the helper control, with no error visible. Debugging
trap: `function_exists('start_form') === true` does NOT imply
`function_exists('customer_list_row')`.

### Tab-footer buttons — native `inputsubmit`, never `ajaxsubmit`

FA's `js/inserts.js` intercepts clicks on elements with class
`ajaxsubmit`/`editbutton`/`navibutton` and routes them through
`JsHttpRequest.request()` — an XHR that swallows navigation (POST persists, the
page never reloads; F5 shows the change). `ksf_FA_Common`'s `FormFooter`
historically emitted `class="ajaxsubmit"`, so Save/Cancel on every app-shell tab
had this symptom. Convention (decided 2026-09): tab-footer Save/Cancel buttons
use FA-native classes (`inputsubmit`) — `FormFooter` defaults `useAjax=false`;
`ajaxsubmit` is only opt-in for content that genuinely wants in-place XHR.
`MasterSummaryTable` row actions (Edit/Delete) already render native
`inputsubmit` rows (the tab controller passes `'ajax' => false`).

## 10. FA DB layer — correct API (gotchas)

- `$db` is **raw mysqli**; FA has **no prepared statements**.
- Use `mysqli_real_escape_string($db, ...)` + `db_query`; read with
  `db_fetch_assoc` (returns `false`, not `null`, at end).
- `db_escape()` is NOT a general escaper — it HTML-decodes; do not rely on it
  for SQL parameter safety.
- For merged/`O_`-style writes the affected-row/insert-id handling differs from
  PDO; test `db_insert_id`/`sql_trail` behavior carefully.
- **Hard rule:** FA modules MUST use native `db_*` calls at runtime. PDO only in
  the standalone/portable side (tests, CLI, non-FA embedding). `DbConnectionInterface`
  (ksf-common-db) is the PDO-shaped contract; `FaDbAdapter` translates to `db_*`.

## 11. Inter-module communication

- Use FA hooks via `hook_invoke` / `hook_invoke_first` / `hook_invoke_all`.
- **4-method discovery contract** a module may expose to others:
  `getModuleConstants()`, `getModuleCapabilities()`, `hasCapability(...)`,
  `respondToCapabilityRequest(...)`.
- Cross-module config read via `ksf_get_value('module.key')` / `ksf_set_value()`
  (HookQueryProviderTrait) — always pass a **variable** (hooks declare `&$data`
  by reference).
- Cross-module CRUD lifecycle via `ksf_crud_event` + `<module>_<action>_<recordType>`
  dual dispatch (CrudEventEmitterTrait); payload: `action`, `module`, `record_type`,
  `record_id`, `data`.
- Cross-module services: `ksf_log()` (ksf_FA_Common) routes to `ksf_log` hook →
  writes `company/<n>/logs/<module>_<date>.log`.

### Event-Driven Architecture

**Every CRUD action that affects cross-module state MUST emit an event.** See
`ProjectDcs/Event-Driven Architecture.md` for full event taxonomy, payload schemas,
and workflow diagrams.

**Core principle:** Modules communicate through events, not direct calls. A module
emits without knowing listeners; a listener acts without knowing the emitter.

**Event naming:** `{object}_{action}` in snake_case (e.g., `stock_reserved`,
`suggested_po_created`, `po_created`).

**Standard payload:**
```php
$data = [
    'module'    => 'ksf_FA_StockReservations',
    'event'     => 'stock_reserved',
    'timestamp' => '2024-01-15 14:30:00',
    // ... event-specific fields
];
```

**Emitter rules:**
- Emit via `hook_invoke_all('{event}', $data)` after state is committed
- Include all standard fields (module, event, timestamp)
- Include relevant IDs for listeners (so_order_no, po_number, etc.)
- Emit even if no listeners (fire-and-forget)

**Listener rules:**
- Implement method named `{event_name}(array &$data)`
- Check team type is enabled before acting (for Teams module)
- Use `class_exists()` guard before using other modules' classes
- Log errors, don't throw (hook methods must be fault-tolerant)

## 12. FA module packaging

- `_init/config` file is **gzip-compressed** `Key: Value` lines (`Name:`, `Version:`,
  `Description:`), version like `2.4.3-<build>`.
- **`_init/config` is shipped in the module source and committed** (siblings keep it
  tracked, e.g. `ksf_FA_HRM` commit `chore(config): bump module version to 2.4.3-1`).
  The `Version:` FA displays and gates at activation is the installed module's
  `_init/config`; `admin/inst_module.php` refuses to activate when
  `check_src_ext_version()` (`includes/packages.inc`) finds the extension's numeric
  prefix below FA's `$src_version` (major.minor). Set `Version: 2.4.x-<build>` (e.g.
  `2.4.3-1`) and keep the module's own release in a separate field (`Build: 1.1.0`).
  Change it **in source** (`git add _init/config`) and let deployment + the FA
  *Install/Activate Extensions* UI reinstall refresh the running copy — never
  hand-edit the gzip on a live install, and never edit the version fields in
  `installed_extensions.php`.
- **The `Version:` in `_init/config` must match the major version of the FA
  platform** the module targets (e.g. `2.4.x` for FrontAccounting 2.4). FA uses
  this to gate module compatibility at install — a mismatched major (e.g. `3.x`
  vs FA `2.x`) makes the module appear incompatible. Keep the module's own
  release/build in a separate field; the FA-compat major is what FA checks.
- `install.sql` schema: hardcoded `0_` prefix; do not use `@TB_PREF@`/`{TB_PREF}`;
  probe existing tables with the bare table name.
- **Deactivation**: use `sql/uninstall.sql` (also hardcoded `0_` prefix), NOT manual
  `db_query()`. In `deactivate_extension()`, read the file and call `run_db_import()`.
  The SQL runner replaces `0_` with the actual company prefix automatically.

  ```php
  function deactivate_extension($company, $force = false)
  {
      $uninstallFile = __DIR__ . '/sql/uninstall.sql';
      if (file_exists($uninstallFile)) {
          $sql = file_get_contents($uninstallFile);
          run_db_import($sql, $company);
      }
      remove_security_section(SS_ksf_FA_ModuleName);
      return parent::deactivate_extension($company, $force);
  }
  ```
- Cross-module/owned classes live in a Packagist package, not a module dir.
  A module must never gate class availability on another module's activation.

## 13. RBAC Architecture (ksf_FA_RBAC)

### Design Document
Full design: `ksf_FA_RBAC/ProjectDcs/RBAC_V2_DESIGN.md`

### Overview
RBAC v2 uses voter-based authorization inspired by Symfony Security, with:
- **Zend RBAC** (`zendframework/zend-permissions-rbac`) for role/permission hierarchy
- **Voter pattern** for CRUD authorization
- **Dynamic assertions** for record-level access
- **Field-level encryption** via `defuse/php-encryption`

### Hooks API

| Hook | Purpose | Returns |
|------|---------|---------|
| `authorize` | Check CRUD access | `true/false/null` |
| `filterRecordList` | Filter list view records | Modified SQL |
| `filterFields` | Restrict field visibility | Field array |
| `encryptField` | Encrypt/decrypt values | Encrypted string |

### Authorization Hook
```php
hook_invoke_all('ksf_FA_RBAC', 'authorize', [
    'user_id'   => $user_id,
    'action'    => 'view', // create, view, edit, delete, list, export
    'module'    => 'customer',
    'resource'  => $customer_obj, // optional for record-level
    'assertion' => function($user_id, $resource) {
        return $resource->isOwnedBy($user_id);
    }
]);
// true = allowed, false = denied, null = abstain (let other voters decide)
```

### Decision Strategies
- `affirmative`: grant if ANY voter allows
- `consensus`: grant if majority allows
- `unanimous`: grant if ALL allow

### Module ACL Registry
Each module declares permissions in `hooks.php` via `getModuleAcl()`:
```php
$data['customer'] = [
    'create' => ['admin', 'manager'],
    'view'   => ['admin', 'manager', 'salesman'],
    'edit'   => ['admin', 'manager'],
    'delete' => ['admin'],
];
```

### Dependency Behavior
| Module | Behavior |
|--------|----------|
| RBAC installed | Full voter-based authorization |
| RBAC missing | All hooks return `null` (native FA permissions) |
| CRM installed | Team-based access via `crm_company_contacts` |
| CRM missing | Record-level uses native `salesman_code` |

### Default Roles
| Role | Description | Inherits |
|------|-------------|----------|
| `admin` | Full access | - |
| `manager` | Business unit | `salesman` |
| `salesman` | Sales rep | `clerk` |
| `clerk` | Data entry | - |
| `ar_clerk` | AR entry | `clerk` |
| `ap_clerk` | AP entry | `clerk` |
| `warehouse` | Warehouse | `clerk` |
| `viewer` | Read-only | - |

## 14. Ecosystem docs

For the package/namespace/dependency map (which repo wraps which, monolith
splits, trait inventory), see the shared ecosystem docs — `MODULE_DIRECTORY.md`,
`PACKAGIST.md`, `APP_TAB_ARCHITECTURE.md` — hardlinked into each repo per
`AGENTS_APPENDIX.md` §Architecture-doc hardlinks. Keep ecosystem *facts* there,
not here.

<!-- Repo-specific appendix to the shared AGENTS.md. Generic conventions live in AGENTS_ARCH.md (hardlinked). -->

```markdown
# ksf_FA_Common — Shared Platform Contracts
> **DO NOT MODIFY** this file directly. Modules consuming these contracts should reference this document.
## Purpose
`ksf_FA_Common` provides platform-level shared contracts, utilities, and extension points that all KSF FrontAccounting modules depend on. It is **not** a business-logic module — it has no UI, no database tables, and no user-facing features.
## Namespace Convention
```
KsfCommon\                         # Root namespace (NOT Ksfraser\FA\Common)
KsfCommon\ContactType\             # Platform contact type definitions
KsfCommon\ContactType\Contract\    # Contact type provider interfaces
KsfCommon\Utils\                   # Utility classes
```
## Contact Types — Platform-Level (NOT Calendar-Specific)
Contact types are a **platform concept** managed by `ksf_FA_Common`.  They
are consumed by Calendar (invitee types), RBAC (user/role types), HRM
(employee/team types), Assets (resource types), and Projects.
Types are persisted in the `ksf_contact_types` DB table (created by
`ksf_FA_Common/sql/install.sql`).  Modules register their types **once**
during `activate_extension()` — no runtime hook dispatch needed.
### Activation order (CRITICAL)
`ksf_FA_Common` **must** be activated first.  All other modules depend on
its table definitions and base types:
1. **ksf_FA_Common**     (creates `ksf_contact_types` table + 4 base types)
2. **ksf_RBAC**          (registers `fa_user` as RBAC account type)
3. **ksf_HRM**           (registers `employee`, `team` types)
4. **ksf_FA_Assets**     (registers `resource` refinements)
5. **ksf_ProjectMgmt**   (registers project-contact types)
6. **ksf_CRM**           (registers `crm_contact` refinements)
7. All other modules
### How modules register their contact types
Every module that provides contact types **must** register them in
`activate_extension()` and clean up in `deactivate_extension()`:
```php
// In ksf_<Module>/hooks.php
use KsfCommon\ContactType\ContactType;
use KsfCommon\ContactType\ContactTypeRegistry;
class hooks_ksf_<Module> extends hooks {
    function activate_extension($company, $check_only=true) {
        // ... existing setup (composer, schema, etc.) ...
        ContactTypeRegistry::registerTypes([
            new ContactType(
                'employee',          // Machine name (lowercase, underscore)
                'Employee',          // Human-readable label
                'ksf_HRM',           // Module identifier
                'HRM employee record with login capability'
            ),
        ]);
        return true;
    }
    function deactivate_extension($company, $check_only=true) {
        ContactTypeRegistry::unregisterModule('ksf_HRM');
        return true;
    }
}
```
### Registration lifecycle
```
Module activation   →  registerTypes()    →  INSERT IGNORE INTO ksf_contact_types
Module deactivation →  unregisterModule() →  DELETE FROM ksf_contact_types WHERE module = ?
```
`registerTypes()` uses `INSERT IGNORE` — re-activating a module does not
error or overwrite existing types.  **First activation wins** (modules
activated earlier take priority).
### Built-in default types
Seeded by `ksf_FA_Common` itself during its activation:
| Name | Label | Owner | Description |
|------|-------|-------|-------------|
| `fa_user` | FA User | ksf_FA_Common | FrontAccounting RBAC user account |
| `crm_contact` | CRM Contact | ksf_FA_Common | Customer or lead managed by the CRM module |
| `resource` | Resource | ksf_FA_Common | Shared resource (room, equipment, vehicle) |
| `ad_hoc` | Ad-hoc | ksf_FA_Common | External invitee without a system record |
Each active module may override these defaults by registering the same name
during its activation.  Because `ksf_FA_Common` activates first, its entries
are created first; later modules' `INSERT IGNORE` does not overwrite them.
To override, a module must explicitly delete and re-insert.
### Consuming the registry
```php
use KsfCommon\ContactType\ContactTypeRegistry;
// Get all registered type names (for validation)
$validTypes = ContactTypeRegistry::getTypeNames();
// Get a single type definition
$type = ContactTypeRegistry::getType('fa_user');
echo $type->getLabel(); // "FA User"
// Check if a type is valid
if (ContactTypeRegistry::isValidType($contactType)) { ... }
// Get full definitions (for API responses)
$definitions = ContactTypeRegistry::getTypeDefinitions();
```
Runtime fallback: if the DB table doesn't exist or is empty, the Registry
returns the four built-in defaults from memory — no consuming module ever
breaks.
## Dependency Requirements
All FA adapter modules that consume `ksf_FA_Common` contracts **must**
include this dependency:
```json
{
    "require": {
        "ksfraser/ksf-fa-common": "*"
    },
    "repositories": [
        { "type": "path", "url": "../ksf_FA_Common" }
    ]
}
```
And in their `hooks.php`, load the autoloader **before** any code that
references `KsfCommon\` classes:
```php
$autoload = dirname(__FILE__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}
```
All `ksf_FA_<Module>` modules **must** depend on `ksf_FA_Common`.  This is
enforced by convention: `ksf_FA_Common` is the platform foundation and must
be activated first.
## Module Registration Checklist
When a new module needs calendar contact types:
- [ ] Add `ksfraser/ksf-fa-common` to `composer.json` (path repository)
- [ ] Load Composer autoloader in `hooks.php`
- [ ] Implement `CalendarContactTypeProviderInterface` (optional, for DI containers)
- [ ] Call `CalendarContactTypeRegistry::registerTypes([...])` in `activate_extension()`
- [ ] Call `CalendarContactTypeRegistry::unregisterModule('ksf_<Module>')` in `deactivate_extension()`
- [ ] Add note in module's `AGENTS.local.md` referencing `ksf_FA_Common`
```

## Scheduler bootstrap & deployment (BR-COM-04, FR-COM-04-005..009)

- **Entry point:** `cron.php` at the package root; run per minute via cron. It
  boots FA's DB (`config_db.php` + mysqli + FA-shaped `db_*` wrappers) and DI's
  `JobRunner(FaJobsAdapter, SystemClock, JobResolverRegistry)`.
- **Autoload robustness (critical for vendored deploy):** when this package is
  vendored (consumer's `vendor/ksfraser/ksf-fa-common/`), `cron.php` has NO own
  `vendor/`. The loader tries, in order: own `vendor/autoload.php` (dev tree),
  consumer's `dirname(__DIR__,3)/vendor/autoload.php` (the module root), then a
  bare PSR-4 fallback over the package `src/`. Keeps the CLI runnable standalone
  AND as a vendored dependency.
- **Common-owned resolvers registered in `cron.php` before the module hook:**
  - `wf.transition.wait` → `WaitTransition(StateMachine(FaStateStore), FaJobsAdapter)` (FR-COM-04-007).
  - `brcom03.notification.digest` → `NotificationDigest(FaInboxStore, FaNotificationPreferenceStore)` (FR-COM-04-008).
  Both are guarded by `class_exists()` so a stale vendored copy degrades
  gracefully. Owning modules supply additional resolvers via the
  `scheduler_resolvers` hook (merged last-wins).
- **Runtime DB contract (verified by e2e):** `db_escape()` MUST be the FA
  variant — self-quoting, `'NULL'` for null, never a bare `real_escape_string`
  result and never pre-quoted by callers. `FaJobsAdapter` builds literal SQL
  with `db_escape($this->stamp($dt))` for every value including datetime
  literals and `next_run_at` persistence in `registerJob`.
- **JSON params:** at FA runtime job `params` are a JSON string column; in-memory
  adapter uses arrays. `JobRunner` normalizes both via `params()`. Store JSON on
  write via `FaJobsAdapter` (`$this->json(...)`), never cast arrays to string.
- **e2e harness:** `e2e_ksf_common_scheduler.php` (package root) mirrors
  `ksf_FA_HRM/e2e_hrm_event_windows.php` — boots the live FA DB, ensures the
  scheduler DDL, seeds a recurring job, drives the engine, asserts run rows +
  idempotence (second consume adds no run), cleans up. Run inside the FA
  container against the deployed (fresh-synced) vendored copy. It caught two
  real adapter bugs the unit suite cannot: unquoted datetime in `listDue`, and
  missing `next_run_at` persistence in `registerJob`.

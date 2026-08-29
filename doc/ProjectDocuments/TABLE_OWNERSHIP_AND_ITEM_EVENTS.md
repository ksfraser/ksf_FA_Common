# ksf_FA_Common Table Ownership & Item-Event Pipeline

> Filed 2026-08-29. Why this doc exists: an architecture review asked "these
> tables look like they belong to other modules — notifications? item sync?
> who actually triggers the item hooks?" This is the evidence-backed map of who
> creates, who reads, who writes, and who publishes each of the platform
> tables, plus the decision to leave ownership as-is for now. So that after
> UAT you can re-grasp the layout without re-tracing the code.

## TL;DR

- ksf_FA_Common creates 5 tables on activation (`sql/install.sql`):
  `0_ksf_contact_types`, `0_ksf_notifications`, `0_fa_job_queue`,
  `0_ksf_item_sync_state`, `0_ksf_item_event_watermark`.
- In practice the **owners/producers are other modules**; Common only hosts the
  shared schema + the class library (registry, services, publishers).
- The item-event pipeline (`item_created` / `item_updated`) has **no FA-core
  trigger**. `items.php` does not broadcast anything. It only fires when a
  producer module calls it directly (InventoryCount) or when Calendar's
  pseudo-cron scanner runs. **ProductAttributes currently does not publish.**
- With only Common + Square + Woo installed, item events never fire and
  Square/Woo never auto-sync items.
- **Decision 2026-08-29: leave ownership as-is.** `0_fa_job_queue` stays in
  Common ("job queue could be common"). Real-cron / SuiteCRM-style scheduler
  work is deferred until ProductAttributes + Square + Woo pass UAT.

## Table-by-table ownership (current state)

### `0_ksf_contact_types` — Cross-module registry (stays in Common)

Producers (seeds/refinements run once in `activate_extension()`):
- ksf_FA_Common install.sql: 4 built-in types (`fa_user`, `crm_contact`,
  `resource`, `ad_hoc`).
- RBAC / HRM / Assets / CRM / Project register/refine their own during
  activation (see AGENTS.md "Activation order (CRITICAL)").

Consumers:
- ksf_FA_Calendar — invitee type filtering, `FA_Cal_Module.php:750`.
- ksf_FA_RBAC — `rbac.contact_type_registered`, `hooks.php:180`.

Verdict: genuinely cross-module contract registry. Its job is to exist before
the consumers read it. Moving it into CRM or HRM would recreate the
dependency-ordering problem Common exists to solve. **Keep in Common.**

### `0_ksf_notifications` — Owned in practice by Calendar (stays for now)

Producers:
- ksf_FA_Calendar only: `FA_Cal_Module.php:142` and `hooks.php:306` →
  `getNotificationService()->enqueue($notification)`.

Consumers:
- ksf_FA_Calendar only: `dispatchPendingReminders()` reads the due rows and
  fires `reminder_delivery_methods` (`FA_Cal_Module.php:1606`,
  invoked from `cal.php:371/1491/1565`).

Vestigial root files (quasi-cron, not wired anywhere):
- `notification.php` — standalone HTTP endpoint (`?action=ack|get`) requiring
  `vendor/autoload.php`.
- `notification_cron.php` — calls `NotificationService::dispatchDue()` but only
  `error_log`s the payload. This is the "quasi true cron that didn't actually
  do anything" — nothing schedules it and it dispatches to no delivery channel.

Verdict: this table is a Calendar feature in disguise; no SMS/browser/CRM
consumers exist in code. Common merely hosts the shared `NotificationService`
class. **Owner is Calendar-in-practice; candidate to move there once Calendar
owns it end-to-end.** Deferred past UAT.

### `0_fa_job_queue` — Common (decision: stays)

- Writer API: `JobQueue::createJob()` — **zero production enqueue callers**
  (tests only).
- Worker: `cron/job_processor.php` → `JobQueue::processJobs(20)` — a standalone
  CLI script; nothing wires it to cron or FA page loads.
- Decision 2026-08-29: keep the queue in Common ("job queue could be common").
  Wiring a worker is deferred past UAT (see backlog).

### `0_ksf_item_sync_state` + `0_ksf_item_event_watermark` — Cross-module item pipeline (stays in Common)

Time since the item-change scanner is genuinely shared by 3+ modules; see next
section. **Keep in Common.**

## Item-event pipeline trigger map

Broadcast mechanism: `ItemEventPublisher::publishCreated()/publishUpdated()`
fire `hook_invoke_all('item_created'/'item_updated', $data)`. External entry
points in `hooks.php`: `publishItemCreated` (98), `publishItemUpdated` (118),
`publishItemChanged` (139), `scanItemChanges` (160), `isItemKnown` (179).

### Producers (the ONLY things that fire the pipeline today)

1. **ksf_FA_InventoryCount** — direct class use:
   `InventoryCountService.php:147` `$publisher->publishUpdated(...)` on count
   adjustment. Not via hook.
2. **ksf_FA_Calendar pseudo-cron scanner** —
   `FA_Cal_Module.php:1709` `runPseudoCronItemScan()` calls
   `hook_invoke('ksf_FA_Common', 'scanItemChanges', …)` (`trigger=pseudo_cron`,
   rate-limited 30s/session), reached via `dispatchPendingReminders()` from
   `cal.php:371/1491/1565`. Only runs if Calendar is installed AND active.

### Consumers (listeners)

- ksf_FA_Square: `hooks.php:285` `item_created`, `:290` `item_updated`.
- ksf_FA_Woocommerce: `hooks.php:216` `item_created`, `:221` `item_updated`.

### Non-participants (gaps)

- **FA core `items.php`** — no `item_created`/`item_updated` broadcast on item
  save. Nothing in core drives the pipeline.
- **FA_ProductAttributes** — publishes nothing today (no `ItemEventPublisher`
  usage, no item hook calls). It is the natural producer for
  "item created / attributes customized" (where PAV sits on item save), and the
  gap to close.

### Operational consequence

With only Common + Square + Woo installed (no Calendar, no InventoryCount),
`item_created`/`item_updated` never fires → Square/Woo **never auto-sync
items**. Auto-sync currently depends on the inventory-count path or Calendar's
pseudo-cron being present.

## Cron / pseudo-cron history & decision

- A **pseudo-cron** (session-rate-limited work on user page loads) was chosen
  before a true cron was wired. Scan/reminder dispatch piggybacks on calendar
  page loads (`cal.php`), not on a scheduler.
- A quasi-cron exists but does nothing meaningful: `notification_cron.php`
  (`dispatchDue()` → `error_log` only) and `notification.php` (standalone HTTP
  endpoint). Neither is scheduled.
- **Decision 2026-08-29:** accept pseudo-cron for the UAT launch.
- **Post-UAT:** study **SuiteCRM's scheduler** — it has a cron that processes
  the job queue and delivers alerts/popups to logged-in users — as the model
  for a true cron (queue worker + notification/alert delivery), before wiring
  `cron/job_processor.php` or building a scheduler.

## Decided (leave as-is) / Post-UAT backlog

Decided now:
- Keep all 5 tables where they are. `0_fa_job_queue` stays in Common.
- Keep pseudo-cron for the launch.

Post-UAT backlog:
1. Research SuiteCRM scheduler (true cron: job queue + alert/popup delivery to
   logged-in users) and model a real worker.
2. Decide + implement FA_ProductAttributes as an `item_created`/`item_updated`
   publisher (bridge the FA-core gap; PAV sits on item save).
3. Re-home `0_ksf_notifications` (+ `NotificationService` delivery) into
   ksf_FA_Calendar and delete the vestigial `notification.php` /
   `notification_cron.php` once Calendar owns it.
4. Wire `cron/job_processor.php` (or SuiteCRM-style scheduler) to a true cron
   if any module starts enqueueing jobs.
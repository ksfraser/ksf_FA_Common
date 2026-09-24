-- ===========================================================================
-- ksf_FA_Common — Shared Platform Contracts
-- Schema: ksf_contact_types
--
-- Platform-level contact type registry.  Contact types are a cross-cutting
-- concept used by Calendar, RBAC, HRM, CRM, Assets, and Project modules.
-- They are NOT specific to Calendar.
--
-- The table is populated during module activation (activate_extension) and
-- cleaned up during deactivation (deactivate_extension).  The Calendar
-- module reads this table to determine valid invitee types; RBAC reads it
-- to determine assignable user types; HRM reads it to determine employee/
-- team scoping; etc.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `0_ksf_contact_types` (
    `name`        VARCHAR(50)  NOT NULL COMMENT 'Machine name (e.g. fa_user, employee, resource, team)',
    `label`       VARCHAR(100) NOT NULL COMMENT 'Human-readable label (e.g. FA User, Employee, Resource)',
    `module`      VARCHAR(100) NOT NULL COMMENT 'Owning module identifier (e.g. ksf_RBAC, ksf_HRM, ksf_CRM)',
    `description` VARCHAR(255) DEFAULT NULL COMMENT 'Optional explanation of what this type represents',
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Platform-level contact type definitions from active KSF modules';

-- NOTE: no type rows are seeded here. The contact types table is created
-- on demand by ContactTypeRegistry::ensureTable(); each type is owned and
-- registered by its natural module during activate_extension() (RBAC -> fa_user,
-- CRM -> crm_contact/lead, Calendar -> invitee, HRM -> employee/team/job_applicant,
-- Assets -> resource).

CREATE TABLE IF NOT EXISTS `0_ksf_notifications` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_module`       VARCHAR(100) NOT NULL,
    `source_ref`          VARCHAR(100) NOT NULL,
    `recipient_user_id`   VARCHAR(50) DEFAULT NULL,
    `notification_type`   VARCHAR(50) NOT NULL DEFAULT 'alert',
    `channel`             VARCHAR(50) NOT NULL DEFAULT 'browser',
    `title`               VARCHAR(255) NOT NULL,
    `body`                TEXT DEFAULT NULL,
    `payload_json`        LONGTEXT DEFAULT NULL,
    `status`              VARCHAR(20) NOT NULL DEFAULT 'pending',
    `scheduled_at`        DATETIME DEFAULT NULL,
    `dispatched_at`       DATETIME DEFAULT NULL,
    `acknowledged_at`     DATETIME DEFAULT NULL,
    `ack_token`           CHAR(64) DEFAULT NULL,
    `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_status_scheduled` (`status`, `scheduled_at`),
    KEY `idx_notifications_recipient_status` (`recipient_user_id`, `status`),
    KEY `idx_notifications_source` (`source_module`, `source_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Shared notification outbox used by calendar, CRM, SMS, and browser alerts';

-- ===========================================================================
-- Job Queue — non-blocking background job processing
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `0_fa_job_queue` (
    `id`            INT           NOT NULL AUTO_INCREMENT,
    `job_type`      VARCHAR(128)  NOT NULL COMMENT 'Job type identifier (e.g. send_email, assign_sales_rep)',
    `payload`       JSON          NULL     COMMENT 'Job parameters as JSON',
    `status`        ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    `priority`      INT           NOT NULL DEFAULT 0 COMMENT 'Higher = more urgent',
    `attempts`      TINYINT       NOT NULL DEFAULT 0,
    `max_attempts`  TINYINT       NOT NULL DEFAULT 3,
    `error_message` TEXT          NULL,
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `scheduled_at`  DATETIME      NULL     COMMENT 'Delay execution until this time',
    `processed_at`  DATETIME      NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_status_priority` (`status`, `priority`, `scheduled_at`),
    INDEX `idx_job_type` (`job_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Non-blocking job queue for background task processing';

-- ===========================================================================
-- Item Event Sync — stock item create/update watermark tracking
--
-- Shared by all KSF modules that react to FA stock item changes (Square,
-- WooCommerce, ...). The ItemChangeWatcher fingerprints every stock item
-- (stock_master + item_codes) and compares against 0_ksf_item_sync_state;
-- differences are broadcast via hook_invoke_all('item_created'/'item_updated').
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `0_ksf_item_sync_state` (
    `stock_id`      VARCHAR(20) NOT NULL COMMENT 'FA stock_id (SKU)',
    `fingerprint`   CHAR(32)    NOT NULL COMMENT 'md5 hash of the last seen item snapshot',
    `first_seen_at` DATETIME    NOT NULL COMMENT 'When the item was first tracked',
    `last_seen_at`  DATETIME    NOT NULL COMMENT 'When the item was last scanned',
    PRIMARY KEY (`stock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Last-seen fingerprints for FA stock item sync events';

CREATE TABLE IF NOT EXISTS `0_ksf_item_event_watermark` (
    `id`        TINYINT(1) NOT NULL DEFAULT 1,
    `watermark` DATETIME   NOT NULL COMMENT 'Timestamp of the most recent watcher scan',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Scan watermark for the shared item change watcher';

-- ===========================================================================
-- Workflow State Machine (BR-COM-02) — per-record process state + append-only
-- transition history. Engine: src/Workflow/StateMachine.php (FaStateStore).
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `0_ksf_wf_state` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `record_type`        VARCHAR(100) NOT NULL COMMENT 'Process record type (e.g. leave_request)',
    `record_id`          VARCHAR(100) NOT NULL COMMENT 'Record id in the owning module',
    `state`              VARCHAR(50)  NOT NULL COMMENT 'Current process state',
    `definition_version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Pinned ProcessDefinition version',
    `created_at`         DATETIME     NOT NULL,
    `updated_at`         DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wf_state_record` (`record_type`, `record_id`),
    KEY `idx_wf_state_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Per-record workflow current state (BR-COM-02)';

CREATE TABLE IF NOT EXISTS `0_ksf_wf_history` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `record_type` VARCHAR(100) NOT NULL COMMENT 'Process record type',
    `record_id`   VARCHAR(100) NOT NULL COMMENT 'Record id',
    `from_state`  VARCHAR(50)  NOT NULL COMMENT 'Previous state',
    `to_state`    VARCHAR(50)  NOT NULL COMMENT 'New state (''from'' on failure)',
    `actor`       VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Acting user / system',
    `reason`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Optional free-text reason',
    `failed`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = transition rolled back (fault)',
    `created_at`  DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_wf_history_record_seq` (`record_type`, `record_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Immutable append-only workflow transition history (BR-COM-02)';

-- ===========================================================================
-- Notification Inbox (BR-COM-03) — per-user append-only activity feed.
--
-- The existing 0_ksf_notifications table (above) is the dispatch/outbox model
-- (status/scheduled_at/ack) used by NotificationRepository. The INBOX is a
-- different subsystem: INSERT-only rows keyed by recipient, with read/dismiss
-- flags on the owner row. Table name deliberately renamed to
-- 0_ksf_notification_inbox to avoid clashing with the outbox (BR-COM-03
-- spec'd 0_ksf_notifications, which was already taken).
-- recipient_uid 0 = @all broadcast placeholder; materialized per user on the
-- first read poll (see InboxStorageInterface::materializeAll).
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `0_ksf_notification_inbox` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `recipient_uid` INT         NOT NULL COMMENT 'FA user id (0 = @all placeholder)',
    `type`         VARCHAR(60)  NOT NULL COMMENT 'Registered notification type (e.g. hrm.leave_approved)',
    `payload`      TEXT         NOT NULL COMMENT 'JSON body fields',
    `ref`          VARCHAR(120) DEFAULT NULL COMMENT 'Deep-link ref (e.g. hr:leave_request:42)',
    `created_at`   DATETIME     NOT NULL,
    `read_at`      DATETIME     DEFAULT NULL,
    `dismissed_at` DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_inbox_recipient_unread` (`recipient_uid`, `read_at`, `created_at`),
    KEY `idx_inbox_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Append-only per-user notification inbox (BR-COM-03)';

CREATE TABLE IF NOT EXISTS `0_ksf_notification_prefs` (
    `recipient_uid` INT         NOT NULL COMMENT 'FA user id',
    `muted_types`   TEXT        DEFAULT NULL COMMENT 'JSON array of muted notification types',
    `digest`        TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '1 = digest delivery requested (v1: flag only)',
    `updated_at`    DATETIME    DEFAULT NULL,
    PRIMARY KEY (`recipient_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Per-user notification preferences (BR-COM-03 FR-COM-03-005)';

CREATE TABLE IF NOT EXISTS `0_ksf_notification_watermark` (
    `recipient_uid` INT UNSIGNED NOT NULL COMMENT 'FA user id',
    `last_all_id`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Highest materialized @all inbox id for this user',
    PRIMARY KEY (`recipient_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Per-user @all materialization watermark (BR-COM-03 FR-COM-03-002)';

-- ===========================================================================
-- Scheduled Automation (BR-COM-04) — job registry + append-only run log.
--
-- job_type: 'recurring' | 'oneshot'. interval_p: '1 hour', '3 days', ... .
-- resolver: CalcRegistry key (BR-COM-01) for the job body. fail_count tracks
-- consecutive failures (FR-COM-04-004): the scheduler packs up and disables a
-- job after 5 consecutive failures. oneshot jobs self-disable (enabled=0,
-- next_run_at=NULL) on success.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `0_ksf_wf_jobs` (
    `job_id`      INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `module`      VARCHAR(60)  NOT NULL COMMENT 'Owning module (schema owner)',
    `job_key`     VARCHAR(120) NOT NULL COMMENT 'e.g. hrm.leave_accrual.monthly',
    `job_type`    VARCHAR(10)  NOT NULL COMMENT 'recurring | oneshot',
    `interval_p`  VARCHAR(20)  NOT NULL COMMENT 'e.g. 1 hour, 3 days, 1 month',
    `resolver`    VARCHAR(120) NOT NULL COMMENT 'CalcRegistry key (BR-COM-01)',
    `params`      TEXT         NULL     COMMENT 'JSON payload for the resolver',
    `enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
    `last_run_at` DATETIME     NULL,
    `next_run_at` DATETIME     NULL     COMMENT 'oneshot: explicit fire time',
    `last_error`  TEXT         NULL,
    `fail_count`  TINYINT      NOT NULL DEFAULT 0 COMMENT 'Consecutive failures (FR-COM-04-004)',
    `created_at`  DATETIME     NOT NULL,
    PRIMARY KEY (`job_id`),
    KEY `idx_next_run` (`next_run_at`, `enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Scheduled automation job registry (BR-COM-04)';

CREATE TABLE IF NOT EXISTS `0_ksf_wf_run_log` (
    `run_id`      INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id`      INT(11) NOT NULL,
    `started_at`  DATETIME NOT NULL,
    `finished_at` DATETIME NULL,
    `status`      VARCHAR(12) NOT NULL COMMENT "ok | failed | locked | skipped",
    `detail`      TEXT NULL,
    `source`      VARCHAR(10) NOT NULL DEFAULT 'cli' COMMENT 'cli | web (FR-COM-04-006)',
    PRIMARY KEY (`run_id`),
    KEY `idx_job_status` (`job_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Append-only scheduler run log (BR-COM-04)';

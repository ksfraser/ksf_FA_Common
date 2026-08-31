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

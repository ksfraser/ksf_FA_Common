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
    `module`      VARCHAR(100) NOT NULL COMMENT 'Owning module identifier (e.g. ksf_FA_Common, ksf_RBAC, ksf_HRM)',
    `description` VARCHAR(255) DEFAULT NULL COMMENT 'Optional explanation of what this type represents',
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Platform-level contact type definitions from active KSF modules';

-- Populate default types that ship with the platform.
INSERT IGNORE INTO `0_ksf_contact_types`
    (`name`, `label`, `module`, `description`)
VALUES
    ('fa_user',     'FA User',     'ksf_FA_Common', 'FrontAccounting RBAC user account'),
    ('crm_contact', 'CRM Contact', 'ksf_FA_Common', 'Customer or lead managed by the CRM module'),
    ('resource',    'Resource',    'ksf_FA_Common', 'Shared resource (room, equipment, vehicle)'),
    ('ad_hoc',      'Ad-hoc',      'ksf_FA_Common', 'External invitee without a system record');

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

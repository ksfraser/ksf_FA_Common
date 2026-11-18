-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_notifications
-- Shared notification outbox.

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

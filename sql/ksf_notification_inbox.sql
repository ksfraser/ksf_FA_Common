-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_notification_inbox
-- Append-only per-user notification inbox (BR-COM-03).

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

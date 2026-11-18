-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_notification_prefs
-- Per-user notification preferences (BR-COM-03).

CREATE TABLE IF NOT EXISTS `0_ksf_notification_prefs` (
    `recipient_uid` INT         NOT NULL COMMENT 'FA user id',
    `muted_types`   TEXT        DEFAULT NULL COMMENT 'JSON array of muted notification types',
    `digest`        TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '1 = digest delivery requested (v1: flag only)',
    `updated_at`    DATETIME    DEFAULT NULL,
    PRIMARY KEY (`recipient_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Per-user notification preferences (BR-COM-03 FR-COM-03-005)';

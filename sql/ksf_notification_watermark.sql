-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_notification_watermark
-- Per-user @all materialization watermark (BR-COM-03).

CREATE TABLE IF NOT EXISTS `0_ksf_notification_watermark` (
    `recipient_uid` INT UNSIGNED NOT NULL COMMENT 'FA user id',
    `last_all_id`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Highest materialized @all inbox id for this user',
    PRIMARY KEY (`recipient_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Per-user @all materialization watermark (BR-COM-03 FR-COM-03-002)';

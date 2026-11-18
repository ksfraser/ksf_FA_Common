-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_item_event_watermark
-- Scan watermark for the shared item change watcher.

CREATE TABLE IF NOT EXISTS `0_ksf_item_event_watermark` (
    `id`        TINYINT(1) NOT NULL DEFAULT 1,
    `watermark` DATETIME   NOT NULL COMMENT 'Timestamp of the most recent watcher scan',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Scan watermark for the shared item change watcher';

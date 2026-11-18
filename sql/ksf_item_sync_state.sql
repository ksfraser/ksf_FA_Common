-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_item_sync_state
-- Last-seen fingerprints for FA stock item sync events.

CREATE TABLE IF NOT EXISTS `0_ksf_item_sync_state` (
    `stock_id`      VARCHAR(20) NOT NULL COMMENT 'FA stock_id (SKU)',
    `fingerprint`   CHAR(32)    NOT NULL COMMENT 'md5 hash of the last seen item snapshot',
    `first_seen_at` DATETIME    NOT NULL COMMENT 'When the item was first tracked',
    `last_seen_at`  DATETIME    NOT NULL COMMENT 'When the item was last scanned',
    PRIMARY KEY (`stock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Last-seen fingerprints for FA stock item sync events';

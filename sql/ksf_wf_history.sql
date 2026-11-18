-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_wf_history
-- Immutable append-only workflow transition history (BR-COM-02).

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

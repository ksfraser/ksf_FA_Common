-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_wf_run_log
-- Append-only scheduler run log (BR-COM-04).

CREATE TABLE IF NOT EXISTS `0_ksf_wf_run_log` (
    `run_id`      INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id`      INT(11) NOT NULL,
    `started_at`  DATETIME NOT NULL,
    `finished_at` DATETIME NULL,
    `status`      VARCHAR(12) NOT NULL COMMENT "ok | failed | locked | skipped",
    `detail`      TEXT NULL,
    `source`      VARCHAR(10) NOT NULL DEFAULT 'cli' COMMENT 'cli | web (FR-COM-04-06)',
    PRIMARY KEY (`run_id`),
    KEY `idx_job_status` (`job_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Append-only scheduler run log (BR-COM-04)';

-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_wf_jobs
-- Scheduled automation job registry (BR-COM-04).

CREATE TABLE IF NOT EXISTS `0_ksf_wf_jobs` (
    `job_id`      INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `module`      VARCHAR(60)  NOT NULL COMMENT 'Owning module (schema owner)',
    `job_key`     VARCHAR(120) NOT NULL COMMENT 'e.g. hrm.leave_accrual.monthly',
    `job_type`    VARCHAR(10)  NOT NULL COMMENT 'recurring | oneshot',
    `interval_p`  VARCHAR(20)  NOT NULL COMMENT 'e.g. 1 hour, 3 days, 1 month',
    `resolver`    VARCHAR(120) NOT NULL COMMENT 'CalcRegistry key (BR-COM-01)',
    `params`      TEXT         NULL     COMMENT 'JSON payload for the resolver',
    `enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
    `last_run_at` DATETIME     NULL,
    `next_run_at` DATETIME     NULL     COMMENT 'oneshot: explicit fire time',
    `last_error`  TEXT         NULL,
    `fail_count`  TINYINT      NOT NULL DEFAULT 0 COMMENT 'Consecutive failures (FR-COM-04-004)',
    `created_at`  DATETIME     NOT NULL,
    PRIMARY KEY (`job_id`),
    KEY `idx_next_run` (`next_run_at`, `enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Scheduled automation job registry (BR-COM-04)';

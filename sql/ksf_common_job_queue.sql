-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_common_job_queue
-- Non-blocking background job queue.

CREATE TABLE IF NOT EXISTS `0_ksf_common_job_queue` (
    `id`            INT           NOT NULL AUTO_INCREMENT,
    `job_type`      VARCHAR(128)  NOT NULL COMMENT 'Job type identifier (e.g. send_email, assign_sales_rep)',
    `payload`       JSON          NULL     COMMENT 'Job parameters as JSON',
    `status`        ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    `priority`      INT           NOT NULL DEFAULT 0 COMMENT 'Higher = more urgent',
    `attempts`      TINYINT       NOT NULL DEFAULT 0,
    `max_attempts`  TINYINT       NOT NULL DEFAULT 3,
    `error_message` TEXT          NULL,
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `scheduled_at`  DATETIME      NULL     COMMENT 'Delay execution until this time',
    `processed_at`  DATETIME      NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_status_priority` (`status`, `priority`, `scheduled_at`),
    INDEX `idx_job_type` (`job_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Non-blocking job queue for background task processing';

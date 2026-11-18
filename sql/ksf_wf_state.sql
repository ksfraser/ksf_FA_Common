-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_wf_state
-- Per-record workflow current state (BR-COM-02).

CREATE TABLE IF NOT EXISTS `0_ksf_wf_state` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `record_type`        VARCHAR(100) NOT NULL COMMENT 'Process record type (e.g. leave_request)',
    `record_id`          VARCHAR(100) NOT NULL COMMENT 'Record id in the owning module',
    `state`              VARCHAR(50)  NOT NULL COMMENT 'Current process state',
    `definition_version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Pinned ProcessDefinition version',
    `created_at`         DATETIME     NOT NULL,
    `updated_at`         DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wf_state_record` (`record_type`, `record_id`),
    KEY `idx_wf_state_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Per-record workflow current state (BR-COM-02)';

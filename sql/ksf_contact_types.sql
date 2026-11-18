-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_contact_types
-- Platform-level contact type registry.

CREATE TABLE IF NOT EXISTS `0_ksf_contact_types` (
    `name`        VARCHAR(50)  NOT NULL COMMENT 'Machine name (e.g. fa_user, employee, resource, team)',
    `label`       VARCHAR(100) NOT NULL COMMENT 'Human-readable label (e.g. FA User, Employee, Resource)',
    `module`      VARCHAR(100) NOT NULL COMMENT 'Owning module identifier (e.g. ksf_RBAC, ksf_HRM, ksf_CRM)',
    `description` VARCHAR(255) DEFAULT NULL COMMENT 'Optional explanation of what this type represents',
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Platform-level contact type definitions from active KSF modules';

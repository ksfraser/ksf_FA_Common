-- ksf_FA_Common — Shared Platform Contracts
-- Table: 0_ksf_modules
-- Shared registry of installed modules.

CREATE TABLE IF NOT EXISTS `0_ksf_modules` (
    `name`      VARCHAR(100) NOT NULL COMMENT 'Module package name',
    `version`   VARCHAR(32)  NOT NULL DEFAULT '' COMMENT 'Installed module version',
    `enabled`   TINYINT(1)   NOT NULL DEFAULT 1,
    `installed` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Shared registry of installed modules';

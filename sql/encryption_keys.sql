-- =============================================================================
-- Multi-Recipient Encryption Key Storage
-- =============================================================================
--
-- Stores encrypted session keys for each recipient.
-- Each field encryption stores multiple keys (user, team, manager chain, etc.)
--
-- @since 1.5.0
-- =============================================================================

CREATE TABLE IF NOT EXISTS `0_encryption_keys` (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type     VARCHAR(32)     NOT NULL COMMENT 'user|team|section|department|company|it_backup',
    entity_id       VARCHAR(64)    NOT NULL COMMENT 'ID or composite like team_123',
    field_name      VARCHAR(64)    NOT NULL COMMENT 'e.g. ssn, tax_id, bank_account',
    recipient_id    VARCHAR(64)    NOT NULL COMMENT 'Who this key is encrypted for',
    encrypted_key   TEXT           NOT NULL COMMENT 'GPG-encrypted symmetric key',
    created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_entity_field_recipient (entity_type, entity_id, field_name, recipient_id),
    KEY idx_entity (entity_type, entity_id),
    KEY idx_recipient (recipient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------
-- Encrypted Data Audit Log
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `0_encryption_audit` (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action          VARCHAR(32)     NOT NULL COMMENT 'encrypt|decrypt|key_created|key_revoked',
    actor_id        VARCHAR(64)    NOT NULL COMMENT 'Who performed the action',
    entity_type     VARCHAR(32)    NULL,
    entity_id       VARCHAR(64)    NULL,
    field_name      VARCHAR(64)    NULL,
    ip_address      VARCHAR(45)    NULL,
    user_agent      VARCHAR(255)   NULL,
    created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_actor (actor_id, created_at),
    KEY idx_entity (entity_type, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
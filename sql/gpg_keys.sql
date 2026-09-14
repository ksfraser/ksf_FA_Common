-- =============================================================================
-- GPG Key Storage
-- =============================================================================
--
-- Stores GPG key pairs for users, teams, and organizational units.
-- Private keys are encrypted with the user's master password.
--
-- @since 1.5.0
-- =============================================================================

CREATE TABLE IF NOT EXISTS `0_gpg_keys` (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type             VARCHAR(32)     NOT NULL COMMENT 'user|team|section|department|company|it_backup',
    entity_id              VARCHAR(64)     NOT NULL COMMENT 'Owner ID',
    email                   VARCHAR(255)    NOT NULL,
    name                    VARCHAR(255)    NOT NULL,
    fingerprint             VARCHAR(64)     NOT NULL UNIQUE,
    public_key              TEXT            NOT NULL COMMENT 'ASCII-armored public key',
    encrypted_private_key   TEXT            NULL COMMENT 'Private key encrypted with user password (AES-256-GCM)',
    revoked_reason          VARCHAR(255)    NULL,
    inactive                TINYINT(1)      NOT NULL DEFAULT 0,
    created_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_entity (entity_type, entity_id),
    KEY idx_email (email),
    KEY idx_fingerprint (fingerprint),
    KEY idx_inactive (inactive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------
-- GPG Key Audit Log
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `0_gpg_key_audit` (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action          VARCHAR(32)     NOT NULL COMMENT 'key_created|key_downloaded|key_revoked|key_used',
    actor_id        VARCHAR(64)     NOT NULL COMMENT 'Who performed action',
    target_entity   VARCHAR(96)     NULL COMMENT 'entity_type:entity_id being acted upon',
    ip_address      VARCHAR(45)     NULL,
    user_agent      VARCHAR(255)    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_actor (actor_id, created_at),
    KEY idx_target (target_entity, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
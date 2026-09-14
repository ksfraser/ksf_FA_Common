<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Utils;

/**
 * GpgKeyManager - Server-side GPG key storage (zero-knowledge compatible).
 *
 * SECURITY MODEL:
 * - Private keys are encrypted CLIENT-SIDE before being sent to server
 * - Server stores ONLY: encrypted blob + verifier for auth
 * - Server CANNOT decrypt private keys (doesn't know password)
 * - DB breach alone does NOT compromise encrypted data
 *
 * TABLE: 0_gpg_keys
 *   - entity_type, entity_id, email, name, fingerprint
 *   - public_key (for encrypting TO this entity)
 *   - encrypted_private_key (JSON blob from client, contains salt)
 *   - inactive, created_at, updated_at
 *
 * TABLE: 0_gpg_auth
 *   - entity_id, verifier (Argon2id hash for auth), salt
 *
 * WORKFLOWS:
 *
 * 1. New Key Generation:
 *    - Client generates RSA keypair with openpgp.js
 *    - Client encrypts private key with password
 *    - Client sends { verifier, encrypted_blob, public_key }
 *    - Server stores (verifier for auth, blob for storage)
 *
 * 2. Login:
 *    - Client fetches salt
 *    - Client hashes password and sends verifier
 *    - Server verifies (never sees password)
 *    - Client downloads encrypted_blob
 *    - Client decrypts private key locally
 *
 * 3. Import Existing Key:
 *    - User exports key from Kleopatra/GPG
 *    - Client imports, re-encrypts with user's FA password
 *    - Server stores (same as new key)
 *
 * 4. External Encryption (keyservers):
 *    - Client looks up public key on keys.openpgp.org
 *    - Client encrypts data to external recipient
 *    - Server stores encrypted data (server never sees plaintext)
 *
 * @since 1.5.0
 */
class GpgKeyManager
{
    /** @var \ksfraser\CommonDb\Contract\DbConnectionInterface */
    private $db;

    /** @var string */
    private $prefix;

    /**
     * @param $db
     * @param string $prefix
     */
    public function __construct($db, string $prefix = '')
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * Initialize tables.
     */
    public function initTables(): void
    {
        $sql1 = "CREATE TABLE IF NOT EXISTS {$this->prefix}gpg_auth (
            entity_id VARCHAR(64) NOT NULL,
            auth_type VARCHAR(16) NOT NULL DEFAULT 'pbkdf2',
            verifier VARCHAR(255) NOT NULL COMMENT 'Password hash for authentication',
            salt VARCHAR(64) NOT NULL COMMENT 'Salt for auth hashing',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql2 = "CREATE TABLE IF NOT EXISTS {$this->prefix}gpg_keys (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(32) NOT NULL COMMENT 'user|team|section|department|company|it_backup',
            entity_id VARCHAR(64) NOT NULL,
            email VARCHAR(255) NOT NULL DEFAULT '',
            name VARCHAR(255) NOT NULL DEFAULT '',
            fingerprint VARCHAR(64) NULL,
            public_key TEXT NULL COMMENT 'Armored public key',
            encrypted_private_key TEXT NULL COMMENT 'Encrypted blob from client (JSON)',
            inactive TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_entity (entity_type, entity_id),
            KEY idx_email (email),
            KEY idx_fingerprint (fingerprint)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->db->executeUpdate($sql1);
        $this->db->executeUpdate($sql2);
    }

    /**
     * Register a new user key (client sends pre-encrypted blob).
     *
     * @param string $entityId
     * @param string $email
     * @param string $publicKeyArmored
     * @param string $encryptedBlob JSON: { v, salt, iv, ct }
     * @param string $verifier Password hash for authentication
     * @param string $salt Salt for verifier
     * @return void
     */
    public function register(string $entityId, string $email, string $publicKeyArmored, string $encryptedBlob, string $verifier, string $salt): void
    {
        $fingerprint = $this->extractFingerprint($publicKeyArmored);

        $sqlAuth = "INSERT INTO {$this->prefix}gpg_auth (entity_id, verifier, salt)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE verifier = ?, salt = ?, updated_at = NOW()";
        $this->db->executeUpdate($sqlAuth, [$entityId, $verifier, $salt, $verifier, $salt]);

        $sqlKey = "INSERT INTO {$this->prefix}gpg_keys
                   (entity_type, entity_id, email, fingerprint, public_key, encrypted_private_key)
                   VALUES ('user', ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE
                       email = VALUES(email),
                       fingerprint = VALUES(fingerprint),
                       public_key = VALUES(public_key),
                       encrypted_private_key = VALUES(encrypted_private_key),
                       updated_at = NOW(),
                       inactive = 0";
        $this->db->executeUpdate($sqlKey, [$entityId, $email, $fingerprint, $publicKeyArmored, $encryptedBlob]);
    }

    /**
     * Get salt for password hashing (for login).
     *
     * @param string $entityId
     * @return string|null
     */
    public function getSalt(string $entityId): ?string
    {
        $sql = "SELECT salt FROM {$this->prefix}gpg_auth WHERE entity_id = ?";
        $result = $this->db->fetchAssoc($sql, [$entityId]);
        return $result ? $result['salt'] : null;
    }

    /**
     * Verify password hash.
     *
     * @param string $entityId
     * @param string $verifier
     * @return bool
     */
    public function verify(string $entityId, string $verifier): bool
    {
        $sql = "SELECT verifier FROM {$this->prefix}gpg_auth WHERE entity_id = ?";
        $result = $this->db->fetchAssoc($sql, [$entityId]);

        if (!$result) {
            return false;
        }

        return hash_equals($result['verifier'], $verifier);
    }

    /**
     * Get encrypted blob for a user (client decrypts).
     *
     * @param string $entityId
     * @return string|null JSON blob
     */
    public function getEncryptedBlob(string $entityId): ?string
    {
        $sql = "SELECT encrypted_private_key FROM {$this->prefix}gpg_keys
                WHERE entity_type = 'user' AND entity_id = ? AND inactive = 0";
        $result = $this->db->fetchAssoc($sql, [$entityId]);
        return $result ? $result['encrypted_private_key'] : null;
    }

    /**
     * Get public key for an entity.
     *
     * @param string $entityType
     * @param string $entityId
     * @return string|null
     */
    public function getPublicKey(string $entityType, string $entityId): ?string
    {
        $sql = "SELECT public_key FROM {$this->prefix}gpg_keys
                WHERE entity_type = ? AND entity_id = ? AND inactive = 0 AND public_key IS NOT NULL";
        $result = $this->db->fetchAssoc($sql, [$entityType, $entityId]);
        return $result ? $result['public_key'] : null;
    }

    /**
     * Store a public key (for teams, departments, IT backup).
     *
     * @param string $entityType
     * @param string $entityId
     * @param string $publicKeyArmored
     * @param string $email
     * @param string $name
     * @return void
     */
    public function storePublicKey(string $entityType, string $entityId, string $publicKeyArmored, string $email = '', string $name = ''): void
    {
        $fingerprint = $this->extractFingerprint($publicKeyArmored);

        $sql = "INSERT INTO {$this->prefix}gpg_keys
                (entity_type, entity_id, email, name, fingerprint, public_key)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    email = VALUES(email),
                    name = VALUES(name),
                    fingerprint = VALUES(fingerprint),
                    public_key = VALUES(public_key),
                    updated_at = NOW(),
                    inactive = 0";
        $this->db->executeUpdate($sql, [$entityType, $entityId, $email, $name, $fingerprint, $publicKeyArmored]);
    }

    /**
     * Check if entity has a key pair.
     *
     * @param string $entityType
     * @param string $entityId
     * @return bool
     */
    public function hasKey(string $entityType, string $entityId): bool
    {
        $sql = "SELECT 1 FROM {$this->prefix}gpg_keys
                WHERE entity_type = ? AND entity_id = ? AND inactive = 0
                AND public_key IS NOT NULL AND encrypted_private_key IS NOT NULL
                LIMIT 1";
        $result = $this->db->fetchAssoc($sql, [$entityType, $entityId]);
        return $result !== null;
    }

    /**
     * Check if entity has a public key only (no private key).
     *
     * @param string $entityType
     * @param string $entityId
     * @return bool
     */
    public function hasPublicKey(string $entityType, string $entityId): bool
    {
        $sql = "SELECT 1 FROM {$this->prefix}gpg_keys
                WHERE entity_type = ? AND entity_id = ? AND inactive = 0
                AND public_key IS NOT NULL
                LIMIT 1";
        $result = $this->db->fetchAssoc($sql, [$entityType, $entityId]);
        return $result !== null;
    }

    /**
     * Revoke a key.
     *
     * @param string $entityType
     * @param string $entityId
     * @param string $reason
     * @return void
     */
    public function revoke(string $entityType, string $entityId, string $reason = ''): void
    {
        $sql = "UPDATE {$this->prefix}gpg_keys
                SET inactive = 1, revoked_reason = ?, updated_at = NOW()
                WHERE entity_type = ? AND entity_id = ?";
        $this->db->executeUpdate($sql, [$reason, $entityType, $entityId]);
    }

    /**
     * Get keys for multiple recipients.
     *
     * @param array $recipients ['user_5', 'team_123', 'dept_1']
     * @return array [recipient_id => armored_public_key]
     */
    public function getPublicKeys(array $recipients): array
    {
        $keys = [];

        foreach ($recipients as $recipient) {
            if (strpos($recipient, '_') === false) {
                continue;
            }

            [$type, $id] = explode('_', $recipient, 2);
            $key = $this->getPublicKey($type, $id);

            if ($key) {
                $keys[$recipient] = $key;
            }
        }

        return $keys;
    }

    /**
     * Extract fingerprint from armored public key.
     */
    private function extractFingerprint(string $armoredKey): string
    {
        $lines = explode("\n", trim($armoredKey));
        $base64 = implode('', array_filter($lines, function($line) {
            return !preg_match('/^-----/', $line);
        }));

        if (empty($base64)) {
            return '';
        }

        $binary = base64_decode($base64);
        return strtoupper(substr(sha1($binary), 0, 40));
    }

    /**
     * Generate a random salt.
     *
     * @return string
     */
    public static function generateSalt(): string
    {
        return bin2hex(random_bytes(32));
    }
}
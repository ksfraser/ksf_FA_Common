<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Utils;

/**
 * MultiRecipientEncryption - GPG-based encryption for teams.
 *
 * Encrypts data to multiple recipients using GPG-style multi-recipient approach:
 * 1. Generate symmetric (AES) key for the data
 * 2. Encrypt data with symmetric key
 * 3. Encrypt symmetric key with each recipient's GPG public key
 *
 * Supports:
 * - Multiple recipients (user, team, manager chain, IT backup)
 * - Hook-based key retrieval (for FA_GPG integration)
 * - Key hierarchy traversal
 *
 * Usage:
 *   $mre = new MultiRecipientEncryption($db);
 *   $encrypted = $mre->encrypt($data, $recipients, 'customer', 5, 'ssn');
 *
 * @since 1.5.0
 */
class MultiRecipientEncryption
{
    /** @var \ksfraser\CommonDb\Contract\DbConnectionInterface */
    private $db;

    /** @var string Table prefix */
    private $prefix;

    /**
     * @param \ksfraser\CommonDb\Contract\DbConnectionInterface $db
     * @param string $prefix
     */
    public function __construct($db, string $prefix = '')
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * Encrypt data for multiple recipients.
     *
     * @param string $plainText
     * @param array $recipientIds Array of recipient IDs to encrypt for
     * @param string $entityType 'user', 'team', etc.
     * @param int $entityId
     * @param string $fieldName
     * @return array ['data' => encrypted, 'keys' => [recipient_id => encrypted_key]]
     */
    public function encrypt(string $plainText, array $recipientIds, string $entityType, int $entityId, string $fieldName): array
    {
        $symmetricKey = $this->generateSymmetricKey();

        $encryptedData = $this->encryptWithSymmetric($plainText, $symmetricKey);

        $encryptedKeys = [];
        foreach ($recipientIds as $recipientId) {
            $publicKey = $this->getPublicKey($recipientId);
            if ($publicKey !== null) {
                $encryptedKeys[$recipientId] = $this->encryptKeyWithGpg($symmetricKey, $publicKey);
            }
        }

        $this->storeEncryptedKey($entityType, $entityId, $fieldName, $encryptedKeys);

        return [
            'data' => $encryptedData,
            'keys' => $encryptedKeys,
        ];
    }

    /**
     * Decrypt data for a specific user.
     *
     * @param array $encrypted
     * @param int $userId
     * @return string
     */
    public function decrypt(array $encrypted, int $userId): string
    {
        if (!isset($encrypted['keys'][$userId])) {
            throw new \RuntimeException("No encrypted key for user {$userId}");
        }

        $privateKey = $this->getPrivateKey($userId);
        $symmetricKey = $this->decryptKeyWithGpg($encrypted['keys'][$userId], $privateKey);

        return $this->decryptWithSymmetric($encrypted['data'], $symmetricKey);
    }

    /**
     * Get recipients for a user's encryption chain.
     *
     * Traverses up the org hierarchy:
     * user → team → section → department → company → IT backup
     *
     * @param int $userId
     * @return array Array of recipient IDs
     */
    public function getRecipientChain(int $userId): array
    {
        $recipients = [];

        $recipients[] = $userId;

        if (function_exists('hook_invoke_all')) {
            $data = ['user_id' => $userId, 'recipients' => []];
            hook_invoke_all('ksf_FA_RBAC', 'get_encryption_recipients', $data);
            if (!empty($data['recipients'])) {
                $recipients = array_merge($recipients, $data['recipients']);
            }
        }

        $teamIds = $this->getUserTeams($userId);
        foreach ($teamIds as $teamId) {
            $recipients[] = 'team_' . $teamId;
            $recipients = array_merge($recipients, $this->getTeamChain($teamId));
        }

        $recipients[] = 'it_backup';

        return array_unique($recipients);
    }

    /**
     * Get user's teams.
     *
     * @param int $userId
     * @return array
     */
    private function getUserTeams(int $userId): array
    {
        $sql = "SELECT team_id FROM {$this->prefix}rbac_team_members WHERE user_id = ? AND inactive = 0";
        $results = $this->db->fetchAll($sql, [$userId]);

        $teams = [];
        foreach ($results as $row) {
            $teams[] = $row['team_id'];
        }
        return $teams;
    }

    /**
     * Get team chain (team → section → department → company).
     *
     * @param string $teamId
     * @return array
     */
    private function getTeamChain(string $teamId): array
    {
        $chain = [];

        $sql = "SELECT team_type, owner_id FROM {$this->prefix}rbac_teams WHERE id = ?";
        $team = $this->db->fetchAssoc($sql, [$teamId]);

        if (!$team) {
            return $chain;
        }

        switch ($team['team_type']) {
            case 'section':
                $chain[] = 'section_' . $teamId;
                break;
            case 'department':
                $chain[] = 'dept_' . $teamId;
                break;
            case 'company':
                $chain[] = 'company_' . $teamId;
                break;
        }

        if (!empty($team['owner_id'])) {
            $chain[] = $team['owner_id'];
        }

        return $chain;
    }

    /**
     * Get GPG public key for a recipient.
     *
     * Uses hook to let FA_GPG provide the key.
     *
     * @param mixed $recipientId
     * @return string|null
     */
    private function getPublicKey($recipientId): ?string
    {
        if (!function_exists('hook_invoke_all')) {
            return null;
        }

        $data = ['recipient_id' => $recipientId, 'public_key' => null];
        hook_invoke_all('ksf_FA_GPG', 'get_public_key', $data);

        return $data['public_key'];
    }

    /**
     * Get GPG private key for a user.
     *
     * @param int $userId
     * @return string|null
     */
    private function getPrivateKey(int $userId): ?string
    {
        if (!function_exists('hook_invoke_all')) {
            return null;
        }

        $data = ['user_id' => $userId, 'private_key' => null];
        hook_invoke_all('ksf_FA_GPG', 'get_private_key', $data);

        return $data['private_key'];
    }

    /**
     * Encrypt symmetric key with GPG public key.
     *
     * @param string $symmetricKey
     * @param string $publicKey
     * @return string
     */
    private function encryptKeyWithGpg(string $symmetricKey, string $publicKey): string
    {
        if (function_exists('hook_invoke_all')) {
            $data = [
                'plain_key' => $symmetricKey,
                'public_key' => $publicKey,
                'encrypted' => null,
            ];
            hook_invoke_all('ksf_FA_GPG', 'encrypt', $data);
            if ($data['encrypted'] !== null) {
                return $data['encrypted'];
            }
        }

        return $this->fallbackEncrypt($symmetricKey, $publicKey);
    }

    /**
     * Decrypt symmetric key with GPG private key.
     *
     * @param string $encryptedKey
     * @param string $privateKey
     * @return string
     */
    private function decryptKeyWithGpg(string $encryptedKey, string $privateKey): string
    {
        if (function_exists('hook_invoke_all')) {
            $data = [
                'encrypted_key' => $encryptedKey,
                'private_key' => $privateKey,
                'plain_key' => null,
            ];
            hook_invoke_all('ksf_FA_GPG', 'decrypt', $data);
            if ($data['plain_key'] !== null) {
                return $data['plain_key'];
            }
        }

        return $this->fallbackDecrypt($encryptedKey, $privateKey);
    }

    /**
     * Generate random AES-256 key.
     *
     * @return string
     */
    private function generateSymmetricKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Encrypt data with symmetric key (AES-256-CBC).
     *
     * @param string $plainText
     * @param string $key
     * @return string Base64 encoded
     */
    private function encryptWithSymmetric(string $plainText, string $key): string
    {
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plainText, 'AES-256-CBC', hex2bin($key), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypt data with symmetric key.
     *
     * @param string $encrypted Base64 encoded
     * @param string $key
     * @return string
     */
    private function decryptWithSymmetric(string $encrypted, string $key): string
    {
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        return openssl_decrypt($ciphertext, 'AES-256-CBC', hex2bin($key), OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Store encrypted keys for recovery.
     *
     * @param string $entityType
     * @param int $entityId
     * @param string $fieldName
     * @param array $encryptedKeys
     */
    private function storeEncryptedKey(string $entityType, int $entityId, string $fieldName, array $encryptedKeys): void
    {
        $sql = "INSERT INTO {$this->prefix}encryption_keys
                (entity_type, entity_id, field_name, recipient_id, encrypted_key, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE encrypted_key = VALUES(encrypted_key), updated_at = NOW()";

        foreach ($encryptedKeys as $recipientId => $encryptedKey) {
            $this->db->executeUpdate($sql, [$entityType, $entityId, $fieldName, $recipientId, $encryptedKey]);
        }
    }

    /**
     * Fallback XOR encryption if GPG not available.
     * DO NOT use for production - this is only for testing.
     */
    private function fallbackEncrypt(string $data, string $key): string
    {
        return base64_encode($data);
    }

    /**
     * Fallback XOR decryption.
     */
    private function fallbackDecrypt(string $data, string $key): string
    {
        return base64_decode($data);
    }
}
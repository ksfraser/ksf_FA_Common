<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Utils;

/**
 * GpgKeyManagementHooks - Interface for FA_GPG key management.
 *
 * FA_GPG implements these hooks to provide GPG key services
 * for the encryption system.
 *
 * Hooks to implement in FA_GPG/hooks.php:
 *   - ksf_gpg_get_public_key     -> getPublicKey($data)
 *   - ksf_gpg_get_private_key    -> getPrivateKey($data)
 *   - ksf_gpg_encrypt            -> encrypt($data)
 *   - ksf_gpg_decrypt            -> decrypt($data)
 *   - ksf_gpg_create_key         -> createKey($data)
 *   - ksf_gpg_revoke_key         -> revokeKey($data)
 *
 * @since 1.5.0
 */
interface GpgKeyManagementHooks
{
    /**
     * Get GPG public key for a recipient.
     *
     * Hook: ksf_gpg_get_public_key
     *
     * @param array &$data {
     *     @var mixed $recipient_id  The recipient (user_id, team_id, etc.)
     *     @var string|null $public_key  Return: ASCII-armored public key
     * }
     * @return void
     */
    public static function getPublicKey(array &$data): void;

    /**
     * Get GPG private key for a user.
     *
     * Hook: ksf_gpg_get_private_key
     *
     * @param array &$data {
     *     @var int $user_id
     *     @var string|null $private_key  Return: ASCII-armored private key
     * }
     * @return void
     */
    public static function getPrivateKey(array &$data): void;

    /**
     * Encrypt data with GPG.
     *
     * Hook: ksf_gpg_encrypt
     *
     * @param array &$data {
     *     @var string $plain_key      Key to encrypt
     *     @var string $public_key     Public key to encrypt with
     *     @var string|null $encrypted  Return: ASCII-armored encrypted message
     * }
     * @return void
     */
    public static function encrypt(array &$data): void;

    /**
     * Decrypt data with GPG.
     *
     * Hook: ksf_gpg_decrypt
     *
     * @param array &$data {
     *     @var string $encrypted_key  ASCII-armored encrypted message
     *     @var string $private_key    Private key to decrypt with
     *     @var string|null $plain_key  Return: decrypted content
     * }
     * @return void
     */
    public static function decrypt(array &$data): void;

    /**
     * Create a GPG key pair.
     *
     * Hook: ksf_gpg_create_key
     *
     * @param array &$data {
     *     @var mixed $entity_id       Owner (user_id, team_id, etc.)
     *     @var string $entity_type    'user', 'team', etc.
     *     @var string $email          Email for key
     *     @var string $name            Display name for key
     *     @var string|null $public_key Return: created public key
     *     @var string|null $private_key Return: created private key (for initial export)
     * }
     * @return void
     */
    public static function createKey(array &$data): void;

    /**
     * Revoke a GPG key.
     *
     * Hook: ksf_gpg_revoke_key
     *
     * @param array &$data {
     *     @var mixed $entity_id
     *     @var string $entity_type
     *     @var string $reason
     * }
     * @return void
     */
    public static function revokeKey(array &$data): void;
}
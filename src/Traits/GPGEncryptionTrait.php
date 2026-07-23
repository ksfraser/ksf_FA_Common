<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Traits;

/**
 * Shared GPG encryption helpers for any module that generates files or
 * emails.  Calls ksf_FA_GPG via hook_invoke_all(); falls back to the
 * original file when GPG is not installed or encryption fails.
 *
 * Usage:
 *   class hooks_my_module extends hooks {
 *       use GPGEncryptionTrait;
 *
 *       function after_file_created($filePath, $email) {
 *           $safe = $this->gpgEncryptFile($filePath, $email);
 *           // $safe is either the encrypted path or the original $filePath
 *       }
 *   }
 *
 * @package KsfCommon\Traits
 * @since   1.1.0
 */
trait GPGEncryptionTrait
{
    /**
     * Encrypt a file for the given recipient email.
     *
     * Falls back to the original file when:
     *  - GPG module is not installed (no hook handler registered)
     *  - Encryption fails (returns error)
     *
     * @param string      $filePath  Absolute path to the plaintext file
     * @param string      $email     Recipient email address
     * @param string      $contactType  FA contact type (default 'customer')
     * @param int         $contactId    FA contact id (default 0)
     * @param string|null $password     Optional password-based encryption
     * @return string Absolute path to the encrypted file, or original on fallback
     *
     * @since 1.1.0
     */
    protected function gpgEncryptFile(
        string $filePath,
        string $email,
        string $contactType = 'customer',
        int    $contactId = 0,
        ?string $password = null
    ): string {
        $data = [
            'file_path'   => $filePath,
            'email'       => $email,
            'contact_type' => $contactType,
            'contact_id'  => $contactId,
        ];
        if ($password !== null) {
            $data['password'] = $password;
        }

        $result = $this->gpgInvokeHook('gpg_encrypt', $data);

        if ($result !== null && !empty($result['success']) && !empty($result['output_path'])) {
            return $result['output_path'];
        }

        return $filePath;
    }

    /**
     * Sign a file using the sender's GPG key.
     *
     * @param string      $filePath            Absolute path to the file
     * @param string      $email               Sender email (for key lookup)
     * @param string      $senderContactType   Sender FA contact type
     * @param int         $senderContactId     Sender FA contact id
     * @return string Absolute path to the signed file, or original on fallback
     *
     * @since 1.1.0
     */
    protected function gpgSignFile(
        string $filePath,
        string $email,
        string $senderContactType = '',
        int    $senderContactId = 0
    ): string {
        $data = [
            'file_path' => $filePath,
            'email'     => $email,
        ];
        if ($senderContactType !== '') {
            $data['sender_contact_type'] = $senderContactType;
        }
        if ($senderContactId > 0) {
            $data['sender_contact_id'] = $senderContactId;
        }

        $result = $this->gpgInvokeHook('gpg_sign', $data);

        if ($result !== null && !empty($result['success']) && !empty($result['output_path'])) {
            return $result['output_path'];
        }

        return $filePath;
    }

    /**
     * Sign and encrypt a file for the given recipient.
     *
     * @param string      $filePath            Absolute path to the file
     * @param string      $email               Recipient email
     * @param string      $contactType         Recipient FA contact type
     * @param int         $contactId           Recipient FA contact id
     * @param string      $senderContactType   Sender FA contact type
     * @param int         $senderContactId     Sender FA contact id
     * @return string Absolute path to the signed+encrypted file, or original on fallback
     *
     * @since 1.1.0
     */
    protected function gpgSignAndEncryptFile(
        string $filePath,
        string $email,
        string $contactType = 'customer',
        int    $contactId = 0,
        string $senderContactType = '',
        int    $senderContactId = 0
    ): string {
        $data = [
            'file_path'   => $filePath,
            'email'       => $email,
            'contact_type' => $contactType,
            'contact_id'  => $contactId,
        ];
        if ($senderContactType !== '') {
            $data['sender_contact_type'] = $senderContactType;
        }
        if ($senderContactId > 0) {
            $data['sender_contact_id'] = $senderContactId;
        }

        $result = $this->gpgInvokeHook('gpg_sign_encrypt', $data);

        if ($result !== null && !empty($result['success']) && !empty($result['output_path'])) {
            return $result['output_path'];
        }

        return $filePath;
    }

    /**
     * Notify the GPG module about a file upload so it can track/encrypt at rest.
     *
     * @param string $filePath     Absolute path to the uploaded file
     * @param string $contactType  FA contact type
     * @param int    $contactId    FA contact id
     * @return array|null The hook result, or null if GPG not installed
     *
     * @since 1.1.0
     */
    protected function gpgTrackFileUpload(
        string $filePath,
        string $contactType = '',
        int    $contactId = 0
    ): ?array {
        $data = [
            'file_path'    => $filePath,
            'contact_type' => $contactType,
            'contact_id'   => $contactId,
        ];

        $result = $this->gpgInvokeHook('gpg_file_upload', $data);

        if ($result !== null && !empty($result['success'])) {
            return $result;
        }

        return null;
    }

    /**
     * Check whether the GPG module is installed and operational.
     *
     * @return bool True if gpg_encrypt hook returns a handler
     *
     * @since 1.1.0
     */
    protected function gpgIsAvailable(): bool
    {
        if (!function_exists('hook_invoke_all')) {
            return false;
        }

        // hook_invoke_all returns [] when no handlers registered
        $data = ['file_path' => '/dev/null', 'email' => 'test@example.com'];
        $results = hook_invoke_all('gpg_encrypt', $data);

        // If the hook returned any non-empty result, GPG module is active
        return !empty($results);
    }

    /**
     * Invoke a GPG hook and return the first result.
     *
     * @param string $hookName  Hook name (e.g. 'gpg_encrypt')
     * @param array  $data      Hook data (passed by reference per FA convention)
     * @return array|null First handler's return value, or null if no handler
     *
     * @since 1.1.0
     */
    protected function gpgInvokeHook(string $hookName, array &$data): ?array
    {
        if (!function_exists('hook_invoke_all')) {
            return null;
        }

        $results = hook_invoke_all($hookName, $data);

        if (empty($results)) {
            return null;
        }

        // hook_invoke_all returns array of results; take the first non-null
        foreach ($results as $result) {
            if (is_array($result)) {
                return $result;
            }
        }

        return null;
    }
}

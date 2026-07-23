<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Traits;

use ksfraser\FrontAccounting\Common\Traits\GPGEncryptionTrait;
use ksfraser\GPG\Hook\GPGHookResponse;

/**
 * Concrete test helper that exposes GPGEncryptionTrait's protected methods.
 *
 * @since 1.1.0
 */
class GPGEncryptionTraitStub
{
    use GPGEncryptionTrait;

    /** @var object|null Test instance that owns the hook stubs */
    public $owner;

    public function __construct($owner = null)
    {
        $this->owner = $owner;
    }

    public function doEncryptFile(string $filePath, string $email, string $contactType = 'customer', int $contactId = 0, ?string $password = null): string
    {
        return $this->gpgEncryptFile($filePath, $email, $contactType, $contactId, $password);
    }

    public function doEncryptFileRaw(string $filePath, string $email, string $contactType = 'customer', int $contactId = 0, ?string $password = null): ?GPGHookResponse
    {
        return $this->gpgEncryptFileRaw($filePath, $email, $contactType, $contactId, $password);
    }

    public function doSignFile(string $filePath, string $email, string $senderContactType = '', int $senderContactId = 0): string
    {
        return $this->gpgSignFile($filePath, $email, $senderContactType, $senderContactId);
    }

    public function doSignFileRaw(string $filePath, string $email, string $senderContactType = '', int $senderContactId = 0): ?GPGHookResponse
    {
        return $this->gpgSignFileRaw($filePath, $email, $senderContactType, $senderContactId);
    }

    public function doSignAndEncryptFile(string $filePath, string $email, string $contactType = 'customer', int $contactId = 0, string $senderContactType = '', int $senderContactId = 0): string
    {
        return $this->gpgSignAndEncryptFile($filePath, $email, $contactType, $contactId, $senderContactType, $senderContactId);
    }

    public function doSignAndEncryptFileRaw(string $filePath, string $email, string $contactType = 'customer', int $contactId = 0, string $senderContactType = '', int $senderContactId = 0): ?GPGHookResponse
    {
        return $this->gpgSignAndEncryptFileRaw($filePath, $email, $contactType, $contactId, $senderContactType, $senderContactId);
    }

    public function doTrackFileUpload(string $filePath, string $contactType = '', int $contactId = 0): bool
    {
        return $this->gpgTrackFileUpload($filePath, $contactType, $contactId);
    }

    public function doIsAvailable(): bool
    {
        return $this->gpgIsAvailable();
    }

    /**
     * Override gpgInvokeHook to delegate to the test owner for responses.
     */
    protected function gpgInvokeHook(string $hookName, array &$data): ?GPGHookResponse
    {
        if ($this->owner !== null) {
            return $this->owner->handleHookCall($hookName, $data);
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Traits;

use ksfraser\GPG\Hook\GPGHookResponse;
use ksfraser\GPG\Hook\GPGTarget;
use ksfraser\GPG\Hook\GPGTargetResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GPGEncryptionTrait.
 *
 * Tests the fallback behaviour (GPG not installed) and the
 * passthrough behaviour (GPG installed, success/failure) using
 * GPGHookResponse DTOs.
 *
 * @package KsfCommon\Tests\Unit\Traits
 * @since   1.1.0
 */
class GPGEncryptionTraitTest extends TestCase
{
    /** @var GPGEncryptionTraitStub|null */
    private $stub;

    /** @var array<int, array{hook: string, data: array}> Captured hook calls */
    private $hookCalls = [];

    /** @var GPGHookResponse|null Controlled response to return */
    private $hookResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hookCalls = [];
        $this->hookResponse = null;
        $this->stub = new GPGEncryptionTraitStub($this);
    }

    protected function tearDown(): void
    {
        $this->stub = null;
        $this->hookCalls = [];
        $this->hookResponse = null;
        parent::tearDown();
    }

    /**
     * Called by the stub when gpgInvokeHook is invoked.
     */
    public function handleHookCall(string $hookName, array &$data): ?GPGHookResponse
    {
        $this->hookCalls[] = ['hook' => $hookName, 'data' => $data];
        return $this->hookResponse;
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private static function makeSuccessResponse(string $original, ?string $encrypted = null, ?string $signed = null): GPGHookResponse
    {
        $target = new GPGTarget('customer', 0, 'test@example.com');
        $result = new GPGTargetResult($target, $original);
        $result->setSuccess(true);
        if ($encrypted !== null) {
            $result->setEncryptedPath($encrypted);
        }
        if ($signed !== null) {
            $result->setSignedPath($signed);
        }

        $response = new GPGHookResponse();
        $response->setSuccess(true);
        $response->addResult($result);
        return $response;
    }

    private static function makeFailureResponse(string $original): GPGHookResponse
    {
        $target = new GPGTarget('customer', 0, 'test@example.com');
        $result = new GPGTargetResult($target, $original);
        $result->setSuccess(false);
        $result->setError('GPG not configured');

        $response = new GPGHookResponse();
        $response->setSuccess(false);
        $response->addResult($result);
        return $response;
    }

    // ── gpgEncryptFile ──────────────────────────────────────────────

    public function testEncryptFileReturnsEncryptedPathOnSuccess(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/invoice.pdf', '/tmp/invoice.pdf.gpg');

        $result = $this->stub->doEncryptFile('/tmp/invoice.pdf', 'client@example.com');

        $this->assertSame('/tmp/invoice.pdf.gpg', $result);
        $this->assertCount(1, $this->hookCalls);
        $this->assertSame('gpg_encrypt', $this->hookCalls[0]['hook']);
        $this->assertSame('/tmp/invoice.pdf', $this->hookCalls[0]['data']['file_path']);
        $this->assertSame('client@example.com', $this->hookCalls[0]['data']['email']);
    }

    public function testEncryptFileReturnsOriginalOnFailure(): void
    {
        $this->hookResponse = self::makeFailureResponse('/tmp/invoice.pdf');

        $result = $this->stub->doEncryptFile('/tmp/invoice.pdf', 'client@example.com');

        $this->assertSame('/tmp/invoice.pdf', $result);
    }

    public function testEncryptFileReturnsOriginalWhenNoHandler(): void
    {
        $result = $this->stub->doEncryptFile('/tmp/invoice.pdf', 'client@example.com');

        $this->assertSame('/tmp/invoice.pdf', $result);
    }

    public function testEncryptFilePassesContactTypeAndId(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/file.pdf', '/tmp/file.gpg');

        $this->stub->doEncryptFile('/tmp/file.pdf', 'a@b.com', 'supplier', 42);

        $this->assertSame('supplier', $this->hookCalls[0]['data']['contact_type']);
        $this->assertSame(42, $this->hookCalls[0]['data']['contact_id']);
    }

    public function testEncryptFilePassesPasswordWhenProvided(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/file.pdf', '/tmp/file.pgp');

        $this->stub->doEncryptFile('/tmp/file.pdf', '', 'customer', 0, 'secret123');

        $this->assertSame('secret123', $this->hookCalls[0]['data']['password']);
    }

    public function testEncryptFileOmitsPasswordWhenNull(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/file.pdf', '/tmp/file.gpg');

        $this->stub->doEncryptFile('/tmp/file.pdf', 'a@b.com');

        $this->assertArrayNotHasKey('password', $this->hookCalls[0]['data']);
    }

    // ── gpgEncryptFileRaw ───────────────────────────────────────────

    public function testEncryptFileRawReturnsDtoOnSuccess(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/invoice.pdf', '/tmp/invoice.pdf.gpg');

        $response = $this->stub->doEncryptFileRaw('/tmp/invoice.pdf', 'client@example.com');

        $this->assertInstanceOf(GPGHookResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame(['/tmp/invoice.pdf.gpg'], $response->getEncryptedPaths());
        $this->assertSame(['/tmp/invoice.pdf'], $response->getOriginalPaths());
    }

    public function testEncryptFileRawReturnsNullWhenNoHandler(): void
    {
        $response = $this->stub->doEncryptFileRaw('/tmp/invoice.pdf', 'client@example.com');

        $this->assertNull($response);
    }

    // ── gpgSignFile ─────────────────────────────────────────────────

    public function testSignFileReturnsSignedPathOnSuccess(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/doc.pdf', null, '/tmp/doc.pdf.sig');

        $result = $this->stub->doSignFile('/tmp/doc.pdf', 'sender@co.com');

        $this->assertSame('/tmp/doc.pdf.sig', $result);
        $this->assertSame('gpg_sign', $this->hookCalls[0]['hook']);
    }

    public function testSignFileReturnsOriginalOnFailure(): void
    {
        $this->hookResponse = self::makeFailureResponse('/tmp/doc.pdf');

        $result = $this->stub->doSignFile('/tmp/doc.pdf', 'sender@co.com');

        $this->assertSame('/tmp/doc.pdf', $result);
    }

    public function testSignFileReturnsOriginalWhenNoHandler(): void
    {
        $result = $this->stub->doSignFile('/tmp/doc.pdf', 'sender@co.com');

        $this->assertSame('/tmp/doc.pdf', $result);
    }

    public function testSignFileIncludesSenderContactInfo(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/doc.pdf', null, '/tmp/doc.pdf.sig');

        $this->stub->doSignFile('/tmp/doc.pdf', 's@co.com', 'employee', 7);

        $this->assertSame('employee', $this->hookCalls[0]['data']['sender_contact_type']);
        $this->assertSame(7, $this->hookCalls[0]['data']['sender_contact_id']);
    }

    public function testSignFileOmitsSenderWhenDefaults(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/doc.pdf', null, '/tmp/doc.pdf.sig');

        $this->stub->doSignFile('/tmp/doc.pdf', 's@co.com');

        $this->assertArrayNotHasKey('sender_contact_type', $this->hookCalls[0]['data']);
        $this->assertArrayNotHasKey('sender_contact_id', $this->hookCalls[0]['data']);
    }

    // ── gpgSignFileRaw ──────────────────────────────────────────────

    public function testSignFileRawReturnsDtoOnSuccess(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/doc.pdf', null, '/tmp/doc.pdf.sig');

        $response = $this->stub->doSignFileRaw('/tmp/doc.pdf', 'sender@co.com');

        $this->assertInstanceOf(GPGHookResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame(['/tmp/doc.pdf.sig'], $response->getSignedPaths());
    }

    // ── gpgSignAndEncryptFile ───────────────────────────────────────

    public function testSignAndEncryptReturnsPathOnSuccess(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/doc.pdf', '/tmp/doc.pdf.gpg');

        $result = $this->stub->doSignAndEncryptFile('/tmp/doc.pdf', 'r@co.com');

        $this->assertSame('/tmp/doc.pdf.gpg', $result);
        $this->assertSame('gpg_sign_encrypt', $this->hookCalls[0]['hook']);
    }

    public function testSignAndEncryptReturnsOriginalOnFailure(): void
    {
        $this->hookResponse = self::makeFailureResponse('/tmp/doc.pdf');

        $result = $this->stub->doSignAndEncryptFile('/tmp/doc.pdf', 'r@co.com');

        $this->assertSame('/tmp/doc.pdf', $result);
    }

    public function testSignAndEncryptReturnsOriginalWhenNoHandler(): void
    {
        $result = $this->stub->doSignAndEncryptFile('/tmp/doc.pdf', 'r@co.com');

        $this->assertSame('/tmp/doc.pdf', $result);
    }

    public function testSignAndEncryptPassesAllParameters(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/doc.pdf', '/tmp/doc.gpg');

        $this->stub->doSignAndEncryptFile(
            '/tmp/doc.pdf',
            'r@co.com',
            'supplier',
            5,
            'employee',
            9
        );

        $data = $this->hookCalls[0]['data'];
        $this->assertSame('r@co.com', $data['email']);
        $this->assertSame('supplier', $data['contact_type']);
        $this->assertSame(5, $data['contact_id']);
        $this->assertSame('employee', $data['sender_contact_type']);
        $this->assertSame(9, $data['sender_contact_id']);
    }

    // ── gpgSignAndEncryptFileRaw ────────────────────────────────────

    public function testSignAndEncryptRawReturnsDtoOnSuccess(): void
    {
        $target = new GPGTarget('customer', 0, 'r@co.com');
        $result = new GPGTargetResult($target, '/tmp/doc.pdf');
        $result->setSuccess(true);
        $result->setEncryptedPath('/tmp/doc.pdf.gpg');
        $result->setSignedPath('/tmp/doc.pdf.sig');

        $response = new GPGHookResponse();
        $response->setSuccess(true);
        $response->addResult($result);
        $this->hookResponse = $response;

        $raw = $this->stub->doSignAndEncryptFileRaw('/tmp/doc.pdf', 'r@co.com');

        $this->assertInstanceOf(GPGHookResponse::class, $raw);
        $this->assertSame(['/tmp/doc.pdf.gpg'], $raw->getEncryptedPaths());
        $this->assertSame(['/tmp/doc.pdf.sig'], $raw->getSignedPaths());
    }

    // ── gpgTrackFileUpload ──────────────────────────────────────────

    public function testTrackFileUploadReturnsTrueOnSuccess(): void
    {
        $response = new GPGHookResponse();
        $response->setSuccess(true);
        $this->hookResponse = $response;

        $result = $this->stub->doTrackFileUpload('/tmp/upload.pdf', 'customer', 3);

        $this->assertTrue($result);
        $this->assertSame('gpg_file_upload', $this->hookCalls[0]['hook']);
    }

    public function testTrackFileUploadReturnsFalseOnFailure(): void
    {
        $this->hookResponse = null;

        $result = $this->stub->doTrackFileUpload('/tmp/upload.pdf');

        $this->assertFalse($result);
    }

    public function testTrackFileUploadReturnsFalseWhenNoHandler(): void
    {
        $result = $this->stub->doTrackFileUpload('/tmp/upload.pdf');

        $this->assertFalse($result);
    }

    // ── gpgIsAvailable ──────────────────────────────────────────────

    public function testGpgIsAvailableReturnsFalseWhenNoFunction(): void
    {
        if (function_exists('hook_invoke_all')) {
            $this->markTestSkipped('hook_invoke_all is defined in this environment');
        }

        $result = $this->stub->doIsAvailable();

        $this->assertFalse($result);
    }

    // ── Edge cases ──────────────────────────────────────────────────

    public function testEncryptFileHandlesSuccessWithNoOutputPath(): void
    {
        $target = new GPGTarget('customer', 0, 'a@b.com');
        $result = new GPGTargetResult($target, '/tmp/file.pdf');
        $result->setSuccess(true);

        $response = new GPGHookResponse();
        $response->setSuccess(true);
        $response->addResult($result);
        $this->hookResponse = $response;

        $path = $this->stub->doEncryptFile('/tmp/file.pdf', 'a@b.com');

        $this->assertSame('/tmp/file.pdf', $path);
    }

    public function testEncryptFileHandlesNonResponseResult(): void
    {
        $path = $this->stub->doEncryptFile('/tmp/file.pdf', 'a@b.com');

        $this->assertSame('/tmp/file.pdf', $path);
    }

    public function testMultipleCallsTrackCorrectly(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/a.pdf', '/tmp/a.gpg');

        $this->stub->doEncryptFile('/tmp/a.pdf', 'a@b.com');
        $this->hookResponse = self::makeSuccessResponse('/tmp/b.pdf', null, '/tmp/b.sig');
        $this->stub->doSignFile('/tmp/b.pdf', 'c@d.com');

        $this->assertCount(2, $this->hookCalls);
        $this->assertSame('gpg_encrypt', $this->hookCalls[0]['hook']);
        $this->assertSame('gpg_sign', $this->hookCalls[1]['hook']);
    }

    public function testEncryptFilePrefersEncryptedOverOriginal(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/file.pdf', '/tmp/file.gpg');

        $path = $this->stub->doEncryptFile('/tmp/file.pdf', 'a@b.com');

        $this->assertSame('/tmp/file.gpg', $path);
    }

    public function testSignFilePrefersSignedOverOriginal(): void
    {
        $this->hookResponse = self::makeSuccessResponse('/tmp/file.pdf', null, '/tmp/file.sig');

        $path = $this->stub->doSignFile('/tmp/file.pdf', 'a@b.com');

        $this->assertSame('/tmp/file.sig', $path);
    }
}

<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Traits;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GPGEncryptionTrait.
 *
 * Tests the fallback behaviour (GPG not installed) and the
 * passthrough behaviour (GPG installed, success/failure).
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

    /** @var array<string, array{success: bool, output_path: string|null, error: string|null}> Hook responses keyed by hook name */
    private $hookResponses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hookCalls = [];
        $this->hookResponses = [];
        $this->stub = new GPGEncryptionTraitStub($this);
    }

    protected function tearDown(): void
    {
        $this->stub = null;
        $this->hookCalls = [];
        $this->hookResponses = [];
        parent::tearDown();
    }

    /**
     * Called by the stub when gpgInvokeHook is invoked.
     * Records the call and returns the controlled response.
     *
     * @param string $hookName
     * @param array  $data
     * @return array|null
     */
    public function handleHookCall(string $hookName, array &$data): ?array
    {
        $this->hookCalls[] = ['hook' => $hookName, 'data' => $data];

        if (isset($this->hookResponses[$hookName])) {
            return $this->hookResponses[$hookName];
        }

        return null;
    }

    // ── gpgEncryptFile ──────────────────────────────────────────────

    public function testEncryptFileReturnsEncryptedPathOnSuccess(): void
    {
        $this->hookResponses['gpg_encrypt'] = [
            'success'     => true,
            'output_path' => '/tmp/invoice.pdf.gpg',
            'error'       => null,
        ];

        $result = $this->stub->doEncryptFile('/tmp/invoice.pdf', 'client@example.com');

        $this->assertSame('/tmp/invoice.pdf.gpg', $result);
        $this->assertCount(1, $this->hookCalls);
        $this->assertSame('gpg_encrypt', $this->hookCalls[0]['hook']);
        $this->assertSame('/tmp/invoice.pdf', $this->hookCalls[0]['data']['file_path']);
        $this->assertSame('client@example.com', $this->hookCalls[0]['data']['email']);
    }

    public function testEncryptFileReturnsOriginalOnFailure(): void
    {
        $this->hookResponses['gpg_encrypt'] = [
            'success'     => false,
            'output_path' => null,
            'error'       => 'GPG not configured',
        ];

        $result = $this->stub->doEncryptFile('/tmp/invoice.pdf', 'client@example.com');

        $this->assertSame('/tmp/invoice.pdf', $result);
    }

    public function testEncryptFileReturnsOriginalWhenNoHandler(): void
    {
        // No hook response set → simulates GPG module not installed
        $result = $this->stub->doEncryptFile('/tmp/invoice.pdf', 'client@example.com');

        $this->assertSame('/tmp/invoice.pdf', $result);
    }

    public function testEncryptFilePassesContactTypeAndId(): void
    {
        $this->hookResponses['gpg_encrypt'] = [
            'success'     => true,
            'output_path' => '/tmp/file.gpg',
            'error'       => null,
        ];

        $this->stub->doEncryptFile('/tmp/file.pdf', 'a@b.com', 'supplier', 42);

        $this->assertSame('supplier', $this->hookCalls[0]['data']['contact_type']);
        $this->assertSame(42, $this->hookCalls[0]['data']['contact_id']);
    }

    public function testEncryptFilePassesPasswordWhenProvided(): void
    {
        $this->hookResponses['gpg_encrypt'] = [
            'success'     => true,
            'output_path' => '/tmp/file.pgp',
            'error'       => null,
        ];

        $this->stub->doEncryptFile('/tmp/file.pdf', '', 'customer', 0, 'secret123');

        $this->assertSame('secret123', $this->hookCalls[0]['data']['password']);
    }

    public function testEncryptFileOmitsPasswordWhenNull(): void
    {
        $this->hookResponses['gpg_encrypt'] = [
            'success'     => true,
            'output_path' => '/tmp/file.gpg',
            'error'       => null,
        ];

        $this->stub->doEncryptFile('/tmp/file.pdf', 'a@b.com');

        $this->assertArrayNotHasKey('password', $this->hookCalls[0]['data']);
    }

    // ── gpgSignFile ─────────────────────────────────────────────────

    public function testSignFileReturnsSignedPathOnSuccess(): void
    {
        $this->hookResponses['gpg_sign'] = [
            'success'     => true,
            'output_path' => '/tmp/doc.pdf.sig',
            'error'       => null,
        ];

        $result = $this->stub->doSignFile('/tmp/doc.pdf', 'sender@co.com');

        $this->assertSame('/tmp/doc.pdf.sig', $result);
        $this->assertSame('gpg_sign', $this->hookCalls[0]['hook']);
    }

    public function testSignFileReturnsOriginalOnFailure(): void
    {
        $this->hookResponses['gpg_sign'] = [
            'success'     => false,
            'output_path' => null,
            'error'       => 'key not found',
        ];

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
        $this->hookResponses['gpg_sign'] = [
            'success'     => true,
            'output_path' => '/tmp/doc.pdf.sig',
            'error'       => null,
        ];

        $this->stub->doSignFile('/tmp/doc.pdf', 's@co.com', 'employee', 7);

        $this->assertSame('employee', $this->hookCalls[0]['data']['sender_contact_type']);
        $this->assertSame(7, $this->hookCalls[0]['data']['sender_contact_id']);
    }

    public function testSignFileOmitsSenderWhenDefaults(): void
    {
        $this->hookResponses['gpg_sign'] = [
            'success'     => true,
            'output_path' => '/tmp/doc.pdf.sig',
            'error'       => null,
        ];

        $this->stub->doSignFile('/tmp/doc.pdf', 's@co.com');

        $this->assertArrayNotHasKey('sender_contact_type', $this->hookCalls[0]['data']);
        $this->assertArrayNotHasKey('sender_contact_id', $this->hookCalls[0]['data']);
    }

    // ── gpgSignAndEncryptFile ───────────────────────────────────────

    public function testSignAndEncryptReturnsPathOnSuccess(): void
    {
        $this->hookResponses['gpg_sign_encrypt'] = [
            'success'     => true,
            'output_path' => '/tmp/doc.pdf.gpg',
            'error'       => null,
        ];

        $result = $this->stub->doSignAndEncryptFile('/tmp/doc.pdf', 'r@co.com');

        $this->assertSame('/tmp/doc.pdf.gpg', $result);
        $this->assertSame('gpg_sign_encrypt', $this->hookCalls[0]['hook']);
    }

    public function testSignAndEncryptReturnsOriginalOnFailure(): void
    {
        $this->hookResponses['gpg_sign_encrypt'] = [
            'success'     => false,
            'output_path' => null,
            'error'       => 'error',
        ];

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
        $this->hookResponses['gpg_sign_encrypt'] = [
            'success'     => true,
            'output_path' => '/tmp/doc.gpg',
            'error'       => null,
        ];

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

    // ── gpgTrackFileUpload ──────────────────────────────────────────

    public function testTrackFileUploadReturnsResultOnSuccess(): void
    {
        $this->hookResponses['gpg_file_upload'] = [
            'success'     => true,
            'output_path' => null,
            'error'       => null,
        ];

        $result = $this->stub->doTrackFileUpload('/tmp/upload.pdf', 'customer', 3);

        $this->assertNotNull($result);
        $this->assertTrue($result['success']);
        $this->assertSame('gpg_file_upload', $this->hookCalls[0]['hook']);
    }

    public function testTrackFileUploadReturnsNullOnFailure(): void
    {
        $this->hookResponses['gpg_file_upload'] = [
            'success'     => false,
            'output_path' => null,
            'error'       => 'fail',
        ];

        $result = $this->stub->doTrackFileUpload('/tmp/upload.pdf');

        $this->assertNull($result);
    }

    public function testTrackFileUploadReturnsNullWhenNoHandler(): void
    {
        $result = $this->stub->doTrackFileUpload('/tmp/upload.pdf');

        $this->assertNull($result);
    }

    // ── gpgIsAvailable ──────────────────────────────────────────────

    public function testGpgIsAvailableReturnsFalseWhenNoFunction(): void
    {
        // In test context, hook_invoke_all is not defined as a real FA function
        // The trait checks function_exists() first
        if (function_exists('hook_invoke_all')) {
            $this->markTestSkipped('hook_invoke_all is defined in this environment');
        }

        $result = $this->stub->doIsAvailable();

        $this->assertFalse($result);
    }

    // ── Edge cases ──────────────────────────────────────────────────

    public function testEncryptFileHandlesEmptyOutputPath(): void
    {
        $this->hookResponses['gpg_encrypt'] = [
            'success'     => true,
            'output_path' => '',
            'error'       => null,
        ];

        $result = $this->stub->doEncryptFile('/tmp/file.pdf', 'a@b.com');

        $this->assertSame('/tmp/file.pdf', $result);
    }

    public function testEncryptFileHandlesNonArrayResult(): void
    {
        // Simulate hook returning a non-array (shouldn't happen, but defensive)
        $result = $this->stub->doEncryptFile('/tmp/file.pdf', 'a@b.com');

        $this->assertSame('/tmp/file.pdf', $result);
    }

    public function testMultipleCallsTrackCorrectly(): void
    {
        $this->hookResponses['gpg_encrypt'] = [
            'success'     => true,
            'output_path' => '/tmp/a.gpg',
            'error'       => null,
        ];

        $this->stub->doEncryptFile('/tmp/a.pdf', 'a@b.com');
        $this->stub->doSignFile('/tmp/b.pdf', 'c@d.com');

        $this->assertCount(2, $this->hookCalls);
        $this->assertSame('gpg_encrypt', $this->hookCalls[0]['hook']);
        $this->assertSame('gpg_sign', $this->hookCalls[1]['hook']);
    }
}

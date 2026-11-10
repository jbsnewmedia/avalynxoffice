<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Backup;

use AvalynxOffice\Service\Backup\BackupCrypto;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class BackupCryptoTest extends TestCase
{
    private string $source;

    private string $target;

    protected function setUp(): void
    {
        $this->source = tempnam(sys_get_temp_dir(), 'aobak-test-src-').'.bin';
        $this->target = tempnam(sys_get_temp_dir(), 'aobak-test-out-').'.enc';
    }

    protected function tearDown(): void
    {
        @unlink($this->source);
        @unlink($this->target);
    }

    public function testEncryptDecryptRoundtripPreservesContent(): void
    {
        // Multiple chunks plus an exact chunk boundary to exercise the
        // streaming paths.
        $content = random_bytes(BackupCrypto::CHUNK_SIZE * 2 + 1234);
        file_put_contents($this->source, $content);

        $crypto = new BackupCrypto();
        $crypto->encryptFile($this->source, $this->target, 'correct horse battery');
        self::assertTrue($crypto->isEncryptedFile($this->target));

        $decrypted = tempnam(sys_get_temp_dir(), 'aobak-test-dec-').'.bin';
        try {
            $crypto->decryptFile($this->target, $decrypted, 'correct horse battery');
            self::assertSame($content, (string) file_get_contents($decrypted));
        } finally {
            @unlink($decrypted);
        }
    }

    public function testWrongPasswordIsRejected(): void
    {
        file_put_contents($this->source, 'top secret content');
        $crypto = new BackupCrypto();
        $crypto->encryptFile($this->source, $this->target, 'right-password');

        $decrypted = tempnam(sys_get_temp_dir(), 'aobak-test-dec-').'.bin';
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('wrong password');
            $crypto->decryptFile($this->target, $decrypted, 'wrong-password');
        } finally {
            @unlink($decrypted);
        }
    }

    public function testNotEncryptedFileIsDetected(): void
    {
        file_put_contents($this->source, "PK\x03\x04 plain zip");
        $crypto = new BackupCrypto();

        self::assertFalse($crypto->isEncryptedFile($this->source));
        self::assertFalse($crypto->isEncryptedFile($this->source.'-does-not-exist'));
    }

    public function testGarbageInputIsRejected(): void
    {
        file_put_contents($this->source, random_bytes(64));
        $crypto = new BackupCrypto();

        $decrypted = tempnam(sys_get_temp_dir(), 'aobak-test-dec-').'.bin';
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('not an encrypted AvalynxOffice backup');
            $crypto->decryptFile($this->source, $decrypted, 'anything');
        } finally {
            @unlink($decrypted);
        }
    }

    public function testTruncatedArchiveIsRejected(): void
    {
        file_put_contents($this->source, random_bytes(500));
        $crypto = new BackupCrypto();
        $crypto->encryptFile($this->source, $this->target, 'some-password');

        $truncated = tempnam(sys_get_temp_dir(), 'aobak-test-cut-').'.enc';
        file_put_contents($truncated, substr((string) file_get_contents($this->target), 0, 80));

        $decrypted = tempnam(sys_get_temp_dir(), 'aobak-test-dec-').'.bin';
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('truncated');
            $crypto->decryptFile($truncated, $decrypted, 'some-password');
        } finally {
            @unlink($truncated);
            @unlink($decrypted);
        }
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Service\Security;

use AvalynxOffice\Service\Security\SecretCrypto;
use PHPUnit\Framework\TestCase;

final class SecretCryptoTest extends TestCase
{
    private const string KEY = 'ylnmhNTxE6uJxSt0NVduo9/tPPFgA9hZf20A6vbHCwM=';
    private const string OTHER_KEY = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    public function testRoundtripWithRandomNonce(): void
    {
        $crypto = new SecretCrypto(self::KEY);

        $first = $crypto->encrypt('s3cret-päss');
        $second = $crypto->encrypt('s3cret-päss');

        self::assertStringStartsWith(SecretCrypto::PREFIX, $first);
        self::assertNotSame($first, $second, 'a random nonce must yield different ciphertexts');
        self::assertStringNotContainsString('s3cret', $first);
        self::assertTrue($crypto->isEncrypted($first));
        self::assertSame('s3cret-päss', $crypto->decrypt($first));
        self::assertSame('s3cret-päss', $crypto->decrypt($second));
        self::assertSame('', $crypto->decrypt($crypto->encrypt('')));
    }

    public function testPlaintextPassesThroughUnchanged(): void
    {
        $crypto = new SecretCrypto(self::KEY);

        self::assertSame('plain-password', $crypto->decrypt('plain-password'));
        self::assertSame('', $crypto->decrypt(''));
        self::assertFalse($crypto->isEncrypted('plain-password'));
    }

    public function testWrongKeyCannotDecrypt(): void
    {
        $encrypted = (new SecretCrypto(self::KEY))->encrypt('s3cret');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wrong AVALYNXOFFICE_ENCRYPTION_KEY');

        (new SecretCrypto(self::OTHER_KEY))->decrypt($encrypted);
    }

    public function testMalformedCiphertextIsRejected(): void
    {
        $crypto = new SecretCrypto(self::KEY);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed');

        $crypto->decrypt(SecretCrypto::PREFIX.'not-base64!!');
    }

    public function testMissingKeyFailsOnFirstUseOnly(): void
    {
        $crypto = new SecretCrypto('');

        // Construction and plaintext passthrough never need the key.
        self::assertSame('plain', $crypto->decrypt('plain'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AVALYNXOFFICE_ENCRYPTION_KEY is not set');

        $crypto->encrypt('secret');
    }

    public function testInvalidKeyIsReported(): void
    {
        $crypto = new SecretCrypto('dG9vLXNob3J0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AVALYNXOFFICE_ENCRYPTION_KEY is invalid');

        $crypto->encrypt('secret');
    }

    public function testEncryptKeysAndDecryptKeysOnlyTouchDeclaredStringValues(): void
    {
        $crypto = new SecretCrypto(self::KEY);
        $config = [
            'host' => 'mail.example.com',
            'password' => 's3cret',
            'empty' => '',
            'count' => 12,
            'missing' => null,
            'other' => 'untouched',
        ];

        $encrypted = $crypto->encryptKeys($config, ['password', 'empty', 'count', 'missing', 'absent']);

        self::assertSame('mail.example.com', $encrypted['host']);
        self::assertSame('untouched', $encrypted['other']);
        self::assertSame('', $encrypted['empty']);
        self::assertSame(12, $encrypted['count']);
        self::assertNull($encrypted['missing']);
        self::assertArrayNotHasKey('absent', $encrypted);
        self::assertStringStartsWith(SecretCrypto::PREFIX, $encrypted['password']);

        // Already encrypted values are not encrypted twice.
        self::assertSame($encrypted, $crypto->encryptKeys($encrypted, ['password']));

        $decrypted = $crypto->decryptKeys($encrypted, ['password', 'empty', 'count', 'missing', 'absent']);
        self::assertSame($config, $decrypted);
    }
}

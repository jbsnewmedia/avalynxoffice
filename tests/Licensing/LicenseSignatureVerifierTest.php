<?php

declare(strict_types=1);

namespace App\Tests\Licensing;

use App\Licensing\Security\LicenseSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class LicenseSignatureVerifierTest extends TestCase
{
    public function testValidSignatureVerifies(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keyPair);
        $public = sodium_crypto_sign_publickey($keyPair);
        $content = 'WOL1.cGF5bG9hZA';
        $signature = sodium_crypto_sign_detached($content, $secret);

        $verifier = new LicenseSignatureVerifier();

        self::assertTrue($verifier->verify($content, $signature, $public));
    }

    public function testTamperedContentFails(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $signature = sodium_crypto_sign_detached('WOL1.original', sodium_crypto_sign_secretkey($keyPair));

        $verifier = new LicenseSignatureVerifier();

        self::assertFalse($verifier->verify('WOL1.tampered', $signature, sodium_crypto_sign_publickey($keyPair)));
    }

    public function testEmptySignatureFails(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $verifier = new LicenseSignatureVerifier();

        self::assertFalse($verifier->verify('WOL1.payload', '', sodium_crypto_sign_publickey($keyPair)));
    }

    public function testWrongKeyLengthFails(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $signature = sodium_crypto_sign_detached('WOL1.payload', sodium_crypto_sign_secretkey($keyPair));
        $verifier = new LicenseSignatureVerifier();

        self::assertFalse($verifier->verify('WOL1.payload', $signature, 'short-key'));
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Licensing;

use AvalynxOffice\Licensing\Security\TrustedPublicKeyProvider;
use PHPUnit\Framework\TestCase;

final class TrustedPublicKeyProviderTest extends TestCase
{
    public function testEmptyConfigurationYieldsNoKeys(): void
    {
        $provider = new TrustedPublicKeyProvider('');

        self::assertSame([], $provider->getKeyIds());
        self::assertNull($provider->getPublicKeyFor('any'));
    }

    public function testValidKeyIsResolvedByItsId(): void
    {
        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $provider = new TrustedPublicKeyProvider(json_encode(['issuer-2026-01' => base64_encode($publicKey)], JSON_THROW_ON_ERROR));

        self::assertSame(['issuer-2026-01'], $provider->getKeyIds());
        self::assertSame($publicKey, $provider->getPublicKeyFor('issuer-2026-01'));
        self::assertNull($provider->getPublicKeyFor('issuer-2027-01'));
    }

    public function testUrlSafeBase64KeyIsAccepted(): void
    {
        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $urlSafe = rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '=');
        $provider = new TrustedPublicKeyProvider(json_encode(['k' => $urlSafe], JSON_THROW_ON_ERROR));

        self::assertSame($publicKey, $provider->getPublicKeyFor('k'));
    }

    public function testInvalidJsonYieldsNoKeysAndDoesNotThrow(): void
    {
        $provider = new TrustedPublicKeyProvider('not-json{');

        self::assertSame([], $provider->getKeyIds());
    }

    public function testNonObjectJsonYieldsNoKeys(): void
    {
        $provider = new TrustedPublicKeyProvider('[1,2]');

        self::assertSame([], $provider->getKeyIds());
    }

    public function testInvalidEntriesAreIgnored(): void
    {
        $validKey = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        $provider = new TrustedPublicKeyProvider(json_encode([
            'bad-type' => 12345,
            'empty-id' => $validKey,
            '' => $validKey,
            'too-short' => base64_encode('short'),
            'valid' => $validKey,
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['empty-id', 'valid'], $provider->getKeyIds());
    }
}

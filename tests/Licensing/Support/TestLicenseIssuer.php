<?php

declare(strict_types=1);

namespace App\Tests\Licensing\Support;

use App\Licensing\Security\LicenseCodec;

/**
 * Test helper that generates an Ed25519 keypair and issues valid license
 * keys. The secret key exists in tests only.
 */
final class TestLicenseIssuer
{
    private string $secretKey;

    private string $publicKey;

    private LicenseCodec $codec;

    public function __construct()
    {
        $keyPair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
        $this->publicKey = sodium_crypto_sign_publickey($keyPair);
        $this->codec = new LicenseCodec();
    }

    public function publicKeyRaw(): string
    {
        return $this->publicKey;
    }

    public function publicKeyBase64(): string
    {
        return base64_encode($this->publicKey);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function issue(array $overrides = []): string
    {
        return $this->codec->encode(self::payload($overrides), $this->secretKey);
    }

    /**
     * Signs an arbitrary (e.g. manipulated) payload.
     *
     * @param array<string, mixed> $payload
     */
    public function issueRawPayload(array $payload): string
    {
        return $this->codec->encode($payload, $this->secretKey);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return array_merge([
            'version' => 1,
            'key_id' => 'test-2026-01',
            'license_id' => 'LIC-TEST-000001',
            'customer_id' => 'CUSTOMER-TEST',
            'organization_id' => 'test-organization',
            'additional_seats' => 7,
            'valid_from' => $now->modify('-1 day')->format(DATE_ATOM),
            'expires_at' => $now->modify('+1 year')->format(DATE_ATOM),
            'installation_id' => null,
        ], $overrides);
    }
}

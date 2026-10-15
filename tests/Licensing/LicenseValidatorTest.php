<?php

declare(strict_types=1);

namespace App\Tests\Licensing;

use App\Licensing\Model\License;
use App\Licensing\Model\LicenseKeyDiagnostic;
use App\Licensing\Model\LicenseKeyStatus;
use App\Licensing\Model\LicenseSource;
use App\Licensing\Security\LicenseCodec;
use App\Licensing\Security\LicenseSignatureVerifier;
use App\Licensing\Security\TrustedPublicKeyProvider;
use App\Licensing\Service\LicenseValidator;
use App\Tests\Licensing\Support\TestLicenseIssuer;
use PHPUnit\Framework\TestCase;

final class LicenseValidatorTest extends TestCase
{
    private TestLicenseIssuer $issuer;

    private LicenseCodec $codec;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->issuer = new TestLicenseIssuer();
        $this->codec = new LicenseCodec();
        $this->now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function createValidator(string $publicKeysJson, string $installationId = 'installation-1'): LicenseValidator
    {
        return new LicenseValidator(
            $this->codec,
            new LicenseSignatureVerifier(),
            new TrustedPublicKeyProvider($publicKeysJson),
            $installationId,
        );
    }

    private function trustedKeysJson(): string
    {
        return json_encode(['test-2026-01' => $this->issuer->publicKeyBase64()], JSON_THROW_ON_ERROR);
    }

    public function testValidKeyIsAcceptedAndTyped(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $result = $validator->validate($this->issuer->issue(), LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertInstanceOf(License::class, $result);
        self::assertSame('LIC-TEST-000001', $result->licenseId);
        self::assertSame('test-organization', $result->organizationId);
        self::assertSame(7, $result->additionalSeats);
        self::assertSame('test-2026-01', $result->keyId);
        self::assertSame(LicenseSource::ENV, $result->source);
        self::assertTrue($result->isActiveAt($this->now));
        self::assertStringEndsWith('…', $result->keyPreview);
        self::assertStringNotContainsString($this->issuer->issue(), $result->keyPreview);
    }

    public function testInvalidFormatIsRejected(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $result = $validator->validate('garbage', LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertInstanceOf(LicenseKeyDiagnostic::class, $result);
        self::assertSame(LicenseKeyStatus::INVALID_FORMAT, $result->status);
    }

    public function testUnknownKeyIdIsRejected(): void
    {
        $validator = $this->createValidator('{}');
        $result = $validator->validate($this->issuer->issue(), LicenseSource::DATABASE, 'test-organization', 'installation-1', $this->now);

        self::assertInstanceOf(LicenseKeyDiagnostic::class, $result);
        self::assertSame(LicenseKeyStatus::UNKNOWN_KEY_ID, $result->status);
        self::assertSame('test-2026-01', $result->keyId);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $key = $this->issuer->issue();
        $parts = explode('.', $key);
        $payload = json_decode(sodium_base642bin($parts[1], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, ''), true, 4);
        $payload['additional_seats'] = 999;
        $tamperedPayload = sodium_bin2base64(json_encode($payload, JSON_THROW_ON_ERROR), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        $result = $validator->validate('WOL1.'.$tamperedPayload.'.'.$parts[2], LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertInstanceOf(LicenseKeyDiagnostic::class, $result);
        self::assertSame(LicenseKeyStatus::INVALID_SIGNATURE, $result->status);
    }

    public function testKeySignedByForeignIssuerIsRejected(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $foreignIssuer = new TestLicenseIssuer();
        $result = $validator->validate($foreignIssuer->issue(), LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertInstanceOf(LicenseKeyDiagnostic::class, $result);
        self::assertSame(LicenseKeyStatus::INVALID_SIGNATURE, $result->status);
    }

    public function testExpiredKeyIsRejected(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $key = $this->issuer->issue([
            'valid_from' => $this->now->modify('-2 years')->format(DATE_ATOM),
            'expires_at' => $this->now->modify('-1 day')->format(DATE_ATOM),
        ]);
        $result = $validator->validate($key, LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertInstanceOf(LicenseKeyDiagnostic::class, $result);
        self::assertSame(LicenseKeyStatus::EXPIRED, $result->status);
        self::assertSame(7, $result->additionalSeats);
    }

    public function testNotYetValidKeyIsRejected(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $key = $this->issuer->issue([
            'valid_from' => $this->now->modify('+1 day')->format(DATE_ATOM),
            'expires_at' => $this->now->modify('+2 years')->format(DATE_ATOM),
        ]);
        $result = $validator->validate($key, LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertInstanceOf(LicenseKeyDiagnostic::class, $result);
        self::assertSame(LicenseKeyStatus::NOT_YET_VALID, $result->status);
    }

    public function testKeyOfForeignOrganizationIsRejected(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $key = $this->issuer->issue(['organization_id' => 'org-other']);
        $result = $validator->validate($key, LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertInstanceOf(LicenseKeyDiagnostic::class, $result);
        self::assertSame(LicenseKeyStatus::WRONG_ORGANIZATION, $result->status);
        self::assertSame('org-other', $result->organizationId);
    }

    public function testInstallationBoundKeyForOtherInstallationIsRejected(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $key = $this->issuer->issue(['installation_id' => 'installation-2']);
        $result = $validator->validate($key, LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertInstanceOf(LicenseKeyDiagnostic::class, $result);
        self::assertSame(LicenseKeyStatus::INSTALLATION_MISMATCH, $result->status);
    }

    public function testInstallationBoundKeyForMatchingInstallationIsAccepted(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $key = $this->issuer->issue(['installation_id' => 'installation-1']);
        $result = $validator->validate($key, LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertInstanceOf(License::class, $result);
    }

    public function testMissingFieldsAreRejected(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $payload = TestLicenseIssuer::payload();
        unset($payload['license_id']);
        $key = $this->issuer->issueRawPayload($payload);
        $result = $validator->validate($key, LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertSame(LicenseKeyStatus::INVALID_PAYLOAD, $result->status);
    }

    public function testUnknownFieldsAreRejected(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $payload = TestLicenseIssuer::payload();
        $payload['extra_field'] = 'nope';
        $key = $this->issuer->issueRawPayload($payload);
        $result = $validator->validate($key, LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertSame(LicenseKeyStatus::INVALID_PAYLOAD, $result->status);
    }

    public function testUnknownSchemaVersionIsRejected(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $payload = TestLicenseIssuer::payload(['version' => 2]);
        $result = $validator->validate($this->issuer->issueRawPayload($payload), LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertSame(LicenseKeyStatus::INVALID_PAYLOAD, $result->status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidValueProvider')]
    public function testStrictSchemaValidation(array $overrides): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $payload = TestLicenseIssuer::payload($overrides);
        $result = $validator->validate($this->issuer->issueRawPayload($payload), LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertSame(LicenseKeyStatus::INVALID_PAYLOAD, $result->status);
    }

    public static function invalidValueProvider(): \Generator
    {
        yield 'zero seats' => [['additional_seats' => 0]];
        yield 'negative seats' => [['additional_seats' => -3]];
        yield 'string seats' => [['additional_seats' => '7']];
        yield 'bool seats' => [['additional_seats' => true]];
        yield 'empty license id' => [['license_id' => '']];
        yield 'empty organization' => [['organization_id' => '']];
        yield 'empty customer' => [['customer_id' => '']];
        yield 'empty key id' => [['key_id' => '']];
        yield 'empty installation id string' => [['installation_id' => '']];
        yield 'invalid valid_from' => [['valid_from' => 'not-a-date']];
        yield 'invalid expires_at' => [['expires_at' => 12345]];
        yield 'expires before valid_from' => [
            [
                'valid_from' => '2027-01-01T00:00:00+00:00',
                'expires_at' => '2026-01-01T00:00:00+00:00',
            ],
        ];
    }

    public function testNonUtcTimestampsAreNormalizedToUtc(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $key = $this->issuer->issue([
            'valid_from' => '2026-01-01T12:00:00+02:00',
            'expires_at' => '2030-01-01T12:00:00+02:00',
        ]);
        $result = $validator->validate($key, LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        self::assertInstanceOf(License::class, $result);
        self::assertSame('UTC', $result->validFrom->getTimezone()->getName());
        self::assertSame('UTC', $result->expiresAt->getTimezone()->getName());
    }

    public function testKeyWithExpiryTimeBoundaryIsExclusive(): void
    {
        $validator = $this->createValidator($this->trustedKeysJson());
        $expiresAt = $this->now;
        $key = $this->issuer->issue([
            'valid_from' => $this->now->modify('-1 hour')->format(DATE_ATOM),
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ]);
        $result = $validator->validate($key, LicenseSource::ENV, 'test-organization', 'installation-1', $this->now);

        // expires_at is exclusive: a key expiring exactly now is expired.
        self::assertSame(LicenseKeyStatus::EXPIRED, $result->status);
    }
}

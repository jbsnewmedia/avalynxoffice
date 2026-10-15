<?php

declare(strict_types=1);

namespace App\Licensing\Service;

use App\Licensing\Model\License;
use App\Licensing\Model\LicenseKeyDiagnostic;
use App\Licensing\Model\LicenseKeyStatus;
use App\Licensing\Model\LicenseSource;
use App\Licensing\Security\LicenseCodec;
use App\Licensing\Security\LicenseSignatureVerifier;
use App\Licensing\Security\TrustedPublicKeyProvider;
use App\Licensing\Validation\LicenseKeyException;

/**
 * Validates a single license key: format, signature, schema, time window,
 * organization binding and optional installation binding.
 */
final readonly class LicenseValidator
{
    public function __construct(
        private LicenseCodec $codec,
        private LicenseSignatureVerifier $signatureVerifier,
        private TrustedPublicKeyProvider $trustedPublicKeys,
    ) {
    }

    /**
     * @return LicenseKeyDiagnostic|License the diagnostic on failure or the license on success
     */
    public function validate(string $licenseKey, LicenseSource $source, string $expectedOrganizationId, string $installationId, \DateTimeImmutable $now): LicenseKeyDiagnostic|License
    {
        $preview = mb_substr($licenseKey, 0, 24).'…';

        try {
            $decoded = $this->codec->decode($licenseKey);
            $payload = $this->codec->decodePayload($decoded['payload_raw']);
            $schema = $this->validateSchema($payload);
        } catch (LicenseKeyException $exception) {
            return new LicenseKeyDiagnostic($preview, $source, $exception->status);
        }

        $keyId = $schema['key_id'];
        $publicKey = $this->trustedPublicKeys->getPublicKeyFor($keyId);
        if (null === $publicKey) {
            return new LicenseKeyDiagnostic($preview, $source, LicenseKeyStatus::UNKNOWN_KEY_ID, keyId: $keyId);
        }

        if (!$this->signatureVerifier->verify($decoded['signed_content'], $decoded['signature'], $publicKey)) {
            return new LicenseKeyDiagnostic($preview, $source, LicenseKeyStatus::INVALID_SIGNATURE, keyId: $keyId);
        }

        $diagnostic = static fn (LicenseKeyStatus $status): LicenseKeyDiagnostic => new LicenseKeyDiagnostic(
            $preview,
            $source,
            $status,
            keyId: $keyId,
            licenseId: $schema['license_id'],
            organizationId: $schema['organization_id'],
            additionalSeats: $schema['additional_seats'],
            validFrom: $schema['valid_from'],
            expiresAt: $schema['expires_at'],
        );

        if ($schema['expires_at'] <= $now) {
            return $diagnostic(LicenseKeyStatus::EXPIRED);
        }

        if ($schema['valid_from'] > $now) {
            return $diagnostic(LicenseKeyStatus::NOT_YET_VALID);
        }

        if ($schema['organization_id'] !== $expectedOrganizationId) {
            return $diagnostic(LicenseKeyStatus::WRONG_ORGANIZATION);
        }

        if (null !== $schema['installation_id'] && $schema['installation_id'] !== $installationId) {
            return $diagnostic(LicenseKeyStatus::INSTALLATION_MISMATCH);
        }

        return License::fromSchema($schema, $source, $preview, $payload);
    }

    /**
     * Strict payload schema check. All timestamps are normalized to UTC.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{version: int, key_id: string, license_id: string, customer_id: string, organization_id: string, additional_seats: int, valid_from: \DateTimeImmutable, expires_at: \DateTimeImmutable, installation_id: string|null}
     *
     * @throws LicenseKeyException with LicenseKeyStatus::INVALID_PAYLOAD
     */
    private function validateSchema(array $payload): array
    {
        $requiredFields = ['version', 'key_id', 'license_id', 'customer_id', 'organization_id', 'additional_seats', 'valid_from', 'expires_at', 'installation_id'];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new LicenseKeyException(sprintf('License payload field "%s" is missing.', $field), LicenseKeyStatus::INVALID_PAYLOAD);
            }
        }

        foreach (array_keys($payload) as $field) {
            if (!in_array($field, $requiredFields, true)) {
                throw new LicenseKeyException(sprintf('License payload field "%s" is unknown.', $field), LicenseKeyStatus::INVALID_PAYLOAD);
            }
        }

        if (1 !== $payload['version']) {
            throw new LicenseKeyException('License payload version is not supported.', LicenseKeyStatus::INVALID_PAYLOAD);
        }

        foreach (['key_id', 'license_id', 'customer_id', 'organization_id'] as $field) {
            if (!is_string($payload[$field]) || '' === trim($payload[$field])) {
                throw new LicenseKeyException(sprintf('License payload field "%s" must be a non-empty string.', $field), LicenseKeyStatus::INVALID_PAYLOAD);
            }
        }

        if (!is_int($payload['additional_seats']) || $payload['additional_seats'] < 1) {
            throw new LicenseKeyException('License payload field "additional_seats" must be a positive integer.', LicenseKeyStatus::INVALID_PAYLOAD);
        }

        $validFrom = $this->parseTimestamp($payload['valid_from'], 'valid_from');
        $expiresAt = $this->parseTimestamp($payload['expires_at'], 'expires_at');

        if ($expiresAt <= $validFrom) {
            throw new LicenseKeyException('License payload field "expires_at" must be after "valid_from".', LicenseKeyStatus::INVALID_PAYLOAD);
        }

        if (null !== $payload['installation_id'] && (!is_string($payload['installation_id']) || '' === trim($payload['installation_id']))) {
            throw new LicenseKeyException('License payload field "installation_id" must be null or a non-empty string.', LicenseKeyStatus::INVALID_PAYLOAD);
        }

        return [
            'version' => 1,
            'key_id' => $payload['key_id'],
            'license_id' => $payload['license_id'],
            'customer_id' => $payload['customer_id'],
            'organization_id' => $payload['organization_id'],
            'additional_seats' => $payload['additional_seats'],
            'valid_from' => $validFrom,
            'expires_at' => $expiresAt,
            'installation_id' => $payload['installation_id'],
        ];
    }

    /**
     * @throws LicenseKeyException with LicenseKeyStatus::INVALID_PAYLOAD
     */
    private function parseTimestamp(mixed $value, string $field): \DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            throw new LicenseKeyException(sprintf('License payload field "%s" must be an ISO-8601 UTC timestamp.', $field), LicenseKeyStatus::INVALID_PAYLOAD);
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new LicenseKeyException(sprintf('License payload field "%s" is not a valid ISO-8601 timestamp.', $field), LicenseKeyStatus::INVALID_PAYLOAD);
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}

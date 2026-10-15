<?php

declare(strict_types=1);

namespace App\Licensing\Model;

/**
 * A successfully validated license key.
 */
final readonly class License
{
    /**
     * @param array<string, mixed> $payload the decoded payload as it was signed
     */
    private function __construct(
        public string $keyId,
        public string $licenseId,
        public string $customerId,
        public string $organizationId,
        public int $additionalSeats,
        public \DateTimeImmutable $validFrom,
        public \DateTimeImmutable $expiresAt,
        public ?string $installationId,
        public LicenseSource $source,
        public string $keyPreview,
        public array $payload,
    ) {
    }

    /**
     * @param array{key_id: string, license_id: string, customer_id: string, organization_id: string, additional_seats: int, valid_from: \DateTimeImmutable, expires_at: \DateTimeImmutable, installation_id: string|null} $schema
     * @param array<string, mixed>                                                                                                                                                                                         $payload
     */
    public static function fromSchema(
        array $schema,
        LicenseSource $source,
        string $keyPreview,
        array $payload,
    ): self {
        return new self(
            keyId: $schema['key_id'],
            licenseId: $schema['license_id'],
            customerId: $schema['customer_id'],
            organizationId: $schema['organization_id'],
            additionalSeats: $schema['additional_seats'],
            validFrom: $schema['valid_from'],
            expiresAt: $schema['expires_at'],
            installationId: $schema['installation_id'],
            source: $source,
            keyPreview: $keyPreview,
            payload: $payload,
        );
    }

    /**
     * Whether the license is active at the given point in time.
     * The end of validity is exclusive.
     */
    public function isActiveAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->validFrom && $now < $this->expiresAt;
    }
}

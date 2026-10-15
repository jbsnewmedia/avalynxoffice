<?php

declare(strict_types=1);

namespace App\Licensing\Model;

/**
 * Diagnostic record for a single configured license key.
 */
final readonly class LicenseKeyDiagnostic
{
    public function __construct(
        public string $keyPreview,
        public LicenseSource $source,
        public LicenseKeyStatus $status,
        public ?string $keyId = null,
        public ?string $licenseId = null,
        public ?string $organizationId = null,
        public ?int $additionalSeats = null,
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}

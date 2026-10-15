<?php

declare(strict_types=1);

namespace App\Licensing\Model;

/**
 * Snapshot of the effective licensing situation of the installation.
 */
final readonly class LicenseEntitlement
{
    /**
     * @param list<License>              $validLicenses
     * @param list<LicenseKeyDiagnostic> $diagnostics
     */
    public function __construct(
        public LicenseStatus $status,
        public int $allowedSeats,
        public int $usedSeats,
        public int $validLicenseCount,
        public array $validLicenses,
        public array $diagnostics,
        public ?\DateTimeImmutable $violationStartedAt,
        public ?\DateTimeImmutable $gracePeriodEndsAt,
        public string $organizationId,
    ) {
    }

    public function getFreeSeats(): int
    {
        return max(0, $this->allowedSeats - $this->usedSeats);
    }

    public function isReadOnly(): bool
    {
        return $this->status->isReadOnly();
    }

    public function isOverLimit(): bool
    {
        return $this->usedSeats > $this->allowedSeats;
    }
}

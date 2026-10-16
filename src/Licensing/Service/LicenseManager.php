<?php

declare(strict_types=1);

namespace App\Licensing\Service;

use App\Licensing\Entity\LicenseState;
use App\Licensing\Model\License;
use App\Licensing\Model\LicenseEntitlement;
use App\Licensing\Model\LicenseKeyDiagnostic;
use App\Licensing\Model\LicenseKeyStatus;
use App\Licensing\Model\LicenseStatus;
use App\Licensing\Provider\CompositeLicenseKeyProvider;
use App\Licensing\Security\TrustedPublicKeyProvider;

/**
 * Aggregates all configured licenses into the effective entitlement:
 * loads the keys, validates them, deduplicates license ids, sums the
 * additional seats, counts used seats and resolves the license status.
 */
final class LicenseManager
{
    private const int BASE_SEATS = 3;

    private ?LicenseEntitlement $entitlement = null;

    public function __construct(
        private readonly CompositeLicenseKeyProvider $keyProvider,
        private readonly LicenseValidator $validator,
        private readonly TrustedPublicKeyProvider $trustedPublicKeyProvider,
        private readonly UserSeatCounter $seatCounter,
        private readonly LicenseStatusResolver $statusResolver,
        private readonly LicenseGracePeriodManager $gracePeriodManager,
        private readonly ?string $configuredOrganizationId = null,
    ) {
    }

    public function getAllowedSeats(): int
    {
        return $this->getEntitlement()->allowedSeats;
    }

    public function getUsedSeats(): int
    {
        return $this->getEntitlement()->usedSeats;
    }

    public function getStatus(): LicenseStatus
    {
        return $this->getEntitlement()->status;
    }

    public function isReadOnly(): bool
    {
        return $this->getEntitlement()->isReadOnly();
    }

    /**
     * @return list<License>
     */
    public function getValidLicenses(): array
    {
        return $this->getEntitlement()->validLicenses;
    }

    public function getEntitlement(): LicenseEntitlement
    {
        return $this->entitlement ??= $this->computeEntitlement();
    }

    /**
     * @return list<LicenseKeyDiagnostic>
     */
    public function getDiagnostics(): array
    {
        return $this->getEntitlement()->diagnostics;
    }

    public function getOrganizationId(): string
    {
        return $this->getEntitlement()->organizationId;
    }

    /**
     * Clears the cached entitlement so the next access recomputes the
     * licensing state. Useful for long-running workers and tests.
     */
    public function reset(): void
    {
        $this->entitlement = null;
    }

    public function areTrustedKeysConfigured(): bool
    {
        return [] !== $this->trustedPublicKeyProvider->getKeyIds();
    }

    private function computeEntitlement(): LicenseEntitlement
    {
        // Naive default-timezone "now": Doctrine persists datetimes without
        // timezone conversion, so all persisted licensing timestamps use the
        // application timezone consistently. Absolute-time comparisons with
        // the UTC license payload timestamps remain correct.
        $now = new \DateTimeImmutable('now');
        $state = $this->gracePeriodManager->getState($this->configuredOrganizationId);
        $expectedOrganizationId = (string) $state->getOrganizationId();

        $validLicenses = [];
        $diagnostics = [];
        $seenLicenseIds = [];

        foreach ($this->keyProvider->getLicenseKeys() as $reference) {
            $result = $this->validator->validate(
                $reference->key,
                $reference->source,
                $expectedOrganizationId,
                (string) $state->getInstallationId(),
                $now,
            );

            if ($result instanceof LicenseKeyDiagnostic) {
                $diagnostics[] = $result;

                continue;
            }

            if (isset($seenLicenseIds[$result->licenseId])) {
                $diagnostics[] = new LicenseKeyDiagnostic(
                    $result->keyPreview,
                    $result->source,
                    LicenseKeyStatus::DUPLICATE,
                    keyId: $result->keyId,
                    licenseId: $result->licenseId,
                    organizationId: $result->organizationId,
                    additionalSeats: $result->additionalSeats,
                    validFrom: $result->validFrom,
                    expiresAt: $result->expiresAt,
                );

                continue;
            }

            $seenLicenseIds[$result->licenseId] = true;
            $validLicenses[] = $result;
        }

        $allowedSeats = self::BASE_SEATS;
        foreach ($validLicenses as $license) {
            $allowedSeats += $license->additionalSeats;
        }

        $usedSeats = $this->seatCounter->countUsedSeats($now);

        $resolution = $this->statusResolver->resolve(
            $state,
            $allowedSeats,
            $usedSeats,
            count($validLicenses),
            $now,
        );

        $this->gracePeriodManager->persistResolution(
            $state,
            $resolution->status,
            $resolution->violationStartedAt,
            $resolution->gracePeriodEndsAt,
            $now,
        );

        return new LicenseEntitlement(
            status: $resolution->status,
            allowedSeats: $allowedSeats,
            usedSeats: $usedSeats,
            validLicenseCount: count($validLicenses),
            validLicenses: $validLicenses,
            diagnostics: $diagnostics,
            violationStartedAt: $resolution->violationStartedAt,
            gracePeriodEndsAt: $resolution->gracePeriodEndsAt,
            organizationId: $expectedOrganizationId,
        );
    }

    /**
     * Returns the persistent state row (for administration purposes).
     */
    public function getState(): LicenseState
    {
        return $this->gracePeriodManager->getState($this->configuredOrganizationId);
    }
}

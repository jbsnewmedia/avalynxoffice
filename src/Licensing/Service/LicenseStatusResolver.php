<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Service;

use AvalynxOffice\Licensing\Entity\LicenseState;
use AvalynxOffice\Licensing\Model\LicenseStatus;

/**
 * Pure resolver for the effective license status.
 *
 * Rules:
 *  - Usage within the allowed seats (3 + sum of valid additional seats)
 *    resolves to COMMUNITY or VALID and clears any violation.
 *  - Exceeding the allowed seats starts a violation. Within the grace
 *    period the status is GRACE_PERIOD; the original violation start and
 *    grace period end are never extended or moved.
 *  - After the grace period ends, the status is READ_ONLY until usage is
 *    back within the allowed seats.
 *  - Clock rollbacks never extend the grace period: if "now" is before the
 *    recorded violation start, the violation start is used for comparison.
 */
final readonly class LicenseStatusResolver
{
    public function __construct(
        private int $gracePeriodDays = 30,
    ) {
    }

    public function resolve(
        LicenseState $state,
        int $allowedSeats,
        int $usedSeats,
        int $validLicenseCount,
        \DateTimeImmutable $now,
    ): LicenseResolution {
        if ($usedSeats <= $allowedSeats) {
            $status = $validLicenseCount > 0 && $allowedSeats > 3 ? LicenseStatus::VALID : LicenseStatus::COMMUNITY;

            return new LicenseResolution(
                status: $status,
                violationStartedAt: null,
                gracePeriodEndsAt: null,
                violationCleared: null !== $state->getViolationStartedAt(),
            );
        }

        $violationStartedAt = $state->getViolationStartedAt();
        $gracePeriodEndsAt = $state->getGracePeriodEndsAt();

        if (null === $violationStartedAt) {
            $gracePeriodEndsAt = $now->modify(sprintf('+%d days', $this->gracePeriodDays));

            return new LicenseResolution(
                status: LicenseStatus::GRACE_PERIOD,
                violationStartedAt: $now,
                gracePeriodEndsAt: $gracePeriodEndsAt,
                violationCleared: false,
            );
        }

        $gracePeriodEndsAt ??= $violationStartedAt->modify(sprintf('+%d days', $this->gracePeriodDays));

        // Clock rollback: compare against the recorded violation start.
        $effectiveNow = $now < $violationStartedAt ? $violationStartedAt : $now;

        $status = $effectiveNow < $gracePeriodEndsAt ? LicenseStatus::GRACE_PERIOD : LicenseStatus::READ_ONLY;

        return new LicenseResolution(
            status: $status,
            violationStartedAt: $violationStartedAt,
            gracePeriodEndsAt: $gracePeriodEndsAt,
            violationCleared: false,
        );
    }
}

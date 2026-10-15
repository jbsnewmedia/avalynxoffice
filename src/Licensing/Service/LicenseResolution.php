<?php

declare(strict_types=1);

namespace App\Licensing\Service;

/**
 * Result of the license status resolution.
 */
final readonly class LicenseResolution
{
    public function __construct(
        public \App\Licensing\Model\LicenseStatus $status,
        public ?\DateTimeImmutable $violationStartedAt,
        public ?\DateTimeImmutable $gracePeriodEndsAt,
        public bool $violationCleared,
    ) {
    }
}

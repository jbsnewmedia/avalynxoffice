<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Licensing\Service\LicenseEnforcementInterface;
use App\Licensing\Service\LicenseOperation;

/**
 * Test double that allows every operation (community mode, never read-only).
 */
final class AllowAllLicenseEnforcement implements LicenseEnforcementInterface
{
    #[\Override]
    public function assertCanPerform(LicenseOperation $operation): void
    {
    }

    #[\Override]
    public function assertUserSeatAvailable(): void
    {
    }

    #[\Override]
    public function isReadOnly(): bool
    {
        return false;
    }
}

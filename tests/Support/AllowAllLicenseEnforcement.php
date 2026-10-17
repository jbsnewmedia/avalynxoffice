<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Support;

use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;

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

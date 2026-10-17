<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Support;

use AvalynxOffice\Licensing\Exception\LicenseReadOnlyException;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;

/**
 * Test double that blocks every write operation (read-only mode).
 */
final class ReadOnlyLicenseEnforcement implements LicenseEnforcementInterface
{
    #[\Override]
    public function assertCanPerform(LicenseOperation $operation): void
    {
        throw new LicenseReadOnlyException($operation);
    }

    #[\Override]
    public function assertUserSeatAvailable(): void
    {
        throw new LicenseReadOnlyException(LicenseOperation::USER_CREATE);
    }

    #[\Override]
    public function isReadOnly(): bool
    {
        return true;
    }
}

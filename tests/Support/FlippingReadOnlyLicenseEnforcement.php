<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Licensing\Exception\LicenseReadOnlyException;
use App\Licensing\Service\LicenseEnforcementInterface;
use App\Licensing\Service\LicenseOperation;

/**
 * Test double that flips from permissive to read-only after a configured
 * number of isReadOnly() calls. Used to simulate the license expiring while
 * a queue worker is running.
 */
final class FlippingReadOnlyLicenseEnforcement implements LicenseEnforcementInterface
{
    private int $calls = 0;

    public function __construct(
        private readonly int $readOnlyFromCall = 2,
    ) {
    }

    #[\Override]
    public function assertCanPerform(LicenseOperation $operation): void
    {
        if ($this->isReadOnly()) {
            throw new LicenseReadOnlyException($operation);
        }
    }

    #[\Override]
    public function assertUserSeatAvailable(): void
    {
    }

    #[\Override]
    public function isReadOnly(): bool
    {
        ++$this->calls;

        return $this->calls >= $this->readOnlyFromCall;
    }
}

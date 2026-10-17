<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Service;

/**
 * Result of the license status resolution.
 */
final readonly class LicenseResolution
{
    public function __construct(
        public \AvalynxOffice\Licensing\Model\LicenseStatus $status,
        public ?\DateTimeImmutable $violationStartedAt,
        public ?\DateTimeImmutable $gracePeriodEndsAt,
        public bool $violationCleared,
    ) {
    }
}

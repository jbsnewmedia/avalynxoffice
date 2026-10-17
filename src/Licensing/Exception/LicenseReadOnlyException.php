<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Exception;

use AvalynxOffice\Licensing\Service\LicenseOperation;

/**
 * Thrown when a user-initiated write operation is blocked because the
 * installation is in read-only mode.
 */
final class LicenseReadOnlyException extends \RuntimeException
{
    public function __construct(
        public readonly LicenseOperation $operation,
    ) {
        parent::__construct(sprintf('The installation is in read-only mode: the operation "%s" is blocked by the license policy.', $operation->value));
    }
}

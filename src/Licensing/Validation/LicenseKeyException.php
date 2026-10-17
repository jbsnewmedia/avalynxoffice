<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Validation;

use AvalynxOffice\Licensing\Model\LicenseKeyStatus;

/**
 * Thrown when a license key cannot be decoded or does not satisfy the schema.
 */
final class LicenseKeyException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly LicenseKeyStatus $status,
    ) {
        parent::__construct($message);
    }
}

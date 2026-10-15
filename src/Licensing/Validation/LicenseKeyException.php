<?php

declare(strict_types=1);

namespace App\Licensing\Validation;

use App\Licensing\Model\LicenseKeyStatus;

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

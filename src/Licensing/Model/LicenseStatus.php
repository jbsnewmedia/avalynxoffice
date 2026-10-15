<?php

declare(strict_types=1);

namespace App\Licensing\Model;

/**
 * Effective license state of the installation.
 */
enum LicenseStatus: string
{
    /**
     * At most three counted user accounts, no commercial license required.
     */
    case COMMUNITY = 'COMMUNITY';

    /**
     * Fully licensed usage within the allowed seats.
     */
    case VALID = 'VALID';

    /**
     * Quota exceeded, but the grace period has not ended yet.
     */
    case GRACE_PERIOD = 'GRACE_PERIOD';

    /**
     * Grace period expired while usage is still above the allowed seats.
     */
    case READ_ONLY = 'READ_ONLY';

    public function isReadOnly(): bool
    {
        return self::READ_ONLY === $this;
    }
}

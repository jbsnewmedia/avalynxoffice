<?php

declare(strict_types=1);

namespace App\Licensing\Model;

/**
 * Origin of a configured license key.
 */
enum LicenseSource: string
{
    case ENV = 'ENV';
    case FILE = 'FILE';
    case DATABASE = 'DATABASE';
}

<?php

declare(strict_types=1);

namespace App\Licensing\Service;

/**
 * User-initiated write operations that are blocked in read-only mode.
 *
 * Internal technical operations (mail import, attachment storage, folder
 * synchronization, search index updates, licensing state persistence,
 * security logging) are deliberately not listed here: they must keep
 * working in read-only mode.
 */
enum LicenseOperation: string
{
    case MAIL_SEND = 'MAIL_SEND';
    case MAIL_MODIFY = 'MAIL_MODIFY';
    case MAIL_DELETE = 'MAIL_DELETE';
    case CALENDAR_WRITE = 'CALENDAR_WRITE';
    case CONTACT_WRITE = 'CONTACT_WRITE';
    case USER_CREATE = 'USER_CREATE';
    case SETTINGS_WRITE = 'SETTINGS_WRITE';
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Service;

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

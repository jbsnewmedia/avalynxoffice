<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Model;

/**
 * Origin of a configured license key.
 */
enum LicenseSource: string
{
    case ENV = 'ENV';
    case FILE = 'FILE';
    case DATABASE = 'DATABASE';
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Model;

/**
 * Diagnostic result status of a single configured license key.
 */
enum LicenseKeyStatus: string
{
    case VALID = 'valid';
    case INVALID_FORMAT = 'invalid_format';
    case UNKNOWN_KEY_ID = 'unknown_key_id';
    case INVALID_SIGNATURE = 'invalid_signature';
    case INVALID_PAYLOAD = 'invalid_payload';
    case NOT_YET_VALID = 'not_yet_valid';
    case EXPIRED = 'expired';
    case WRONG_ORGANIZATION = 'wrong_organization';
    case INSTALLATION_MISMATCH = 'installation_mismatch';
    case DUPLICATE = 'duplicate';
}

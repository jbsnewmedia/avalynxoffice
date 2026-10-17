<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity\Enum;

enum EmailAddressType: string
{
    case RECIPIENT = 'recipient';
    case CC = 'cc';
    case SENDER = 'sender';
    case BCC = 'bcc';
    case REPLY_TO = 'reply_to';
}

<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum EmailAddressType: string
{
    case RECIPIENT = 'recipient';
    case CC = 'cc';
    case SENDER = 'sender';
    case BCC = 'bcc';
    case REPLY_TO = 'reply_to';
}

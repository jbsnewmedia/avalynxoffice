<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum FolderFunction: string
{
    case NONE = 'none';
    case MAILBOX = 'mailbox';
    case INBOX = 'inbox';
    case OUTBOX = 'outbox';
    case DRAFTS = 'drafts';

    public function translationKey(): string
    {
        return 'folder.function.'.$this->value;
    }
}

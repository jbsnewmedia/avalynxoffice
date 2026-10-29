<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity\Enum;

enum FolderFunction: string
{
    case NONE = 'none';
    case MAILBOX = 'mailbox';
    case INBOX = 'inbox';
    case OUTBOX = 'outbox';
    case SCHEDULED = 'scheduled';
    case DRAFTS = 'drafts';
    case TRASH = 'trash';

    public function translationKey(): string
    {
        return 'folder.function.'.$this->value;
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Mail;

use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\MailboxRepository;

/**
 * Resolves the mailboxes visible to a user. Admins see every mailbox,
 * everyone else only the mailboxes linked to their account.
 */
final readonly class UserMailboxProvider
{
    public function __construct(
        private MailboxRepository $mailboxRepository,
    ) {
    }

    /**
     * @return list<Mailbox>
     */
    public function forUser(User $user): array
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->mailboxRepository->findBy([], ['name' => 'ASC']);
        }

        return array_values($user->getMailboxes()->toArray());
    }
}

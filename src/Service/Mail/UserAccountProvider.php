<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Mail;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\AccountRepository;

/**
 * Resolves the accounts visible to a user. Admins see every account,
 * everyone else only the accounts linked to their account.
 */
final readonly class UserAccountProvider
{
    public function __construct(
        private AccountRepository $accountRepository,
    ) {
    }

    /**
     * @return list<Account>
     */
    public function forUser(User $user): array
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->accountRepository->findBy([], ['name' => 'ASC']);
        }

        return array_values($user->getAccounts()->toArray());
    }
}

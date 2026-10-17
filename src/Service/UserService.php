<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\UserRepository;

/**
 * Central user/account helpers: automatic administrator onboarding and
 * protection of the last active administrator account.
 */
final readonly class UserService
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    /**
     * Assigns the roles for a newly created user. As long as the
     * installation has no active administrator, the first user becomes the
     * administrator automatically; everybody else starts as a regular user.
     */
    public function applyInitialRoles(User $user): void
    {
        $user->setRoles(
            [] === $this->userRepository->findActiveAdmins() ? ['ROLE_ADMIN'] : ['ROLE_USER'],
        );
    }

    /**
     * True when the given user is the only active administrator. Deactivating
     * this account or revoking its role would leave the installation without
     * any administrator and must be prevented.
     */
    public function isLastActiveAdmin(User $user): bool
    {
        if (!$user->isActive() || !in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return false;
        }

        return 1 >= count($this->userRepository->findActiveAdmins());
    }

    /**
     * Grants or revokes the administrator role. Revoking is rejected for the
     * last active administrator.
     *
     * @throws \DomainException when the last active administrator would lose the role
     */
    public function toggleAdmin(User $user): void
    {
        $roles = $user->getRoles();
        $isAdmin = in_array('ROLE_ADMIN', $roles, true);

        if ($isAdmin) {
            if ($this->isLastActiveAdmin($user)) {
                throw new \DomainException('The last active administrator cannot lose the administrator role.');
            }

            $roles = array_values(array_diff($roles, ['ROLE_ADMIN']));
        } else {
            $roles[] = 'ROLE_ADMIN';
        }

        $user->setRoles($roles);
    }
}

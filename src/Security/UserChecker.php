<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Security;

use AvalynxOffice\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Blocks login for deactivated users. Deactivation reduces the
 * license seat usage and must stay possible in read-only mode.
 */
final class UserChecker implements UserCheckerInterface
{
    /**
     * Same message (and translation key of the "security" domain) the
     * authenticator shows for wrong credentials, so a login attempt cannot
     * tell a deactivated account apart from a non-existent one.
     */
    public const string MESSAGE = 'Invalid credentials.';

    #[\Override]
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException(self::MESSAGE);
        }
    }

    #[\Override]
    public function checkPostAuth(UserInterface $user, ?\Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token = null): void
    {
    }
}

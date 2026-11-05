<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Security;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Service\Idn\IdnService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Entity user provider with IDN normalization: the identifier a user typed
 * ("User@Bästoff.DE") is normalized to the stored punycode form
 * ("user@xn--bstoff-bua.de") before the lookup. Without this, the login
 * would only work in browsers that punycode-normalize email inputs on their
 * own (Chromium does, Firefox does not).
 *
 * Replaces the plain entity provider from security.yaml; session refresh and
 * remember-me auto-login use the same normalization.
 *
 * @implements UserProviderInterface<User>
 */
final readonly class AppUserProvider implements UserProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IdnService $idnService,
    ) {
    }

    #[\Override]
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $email = $this->idnService->toAscii(strtolower(trim($identifier)));

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $user) {
            throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
        }

        return $user;
    }

    #[\Override]
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new \InvalidArgumentException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $refreshedUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $user->getUserIdentifier()]);
        if (null === $refreshedUser) {
            throw new UserNotFoundException(sprintf('User "%s" no longer exists.', $user->getUserIdentifier()));
        }

        return $refreshedUser;
    }

    #[\Override]
    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
}

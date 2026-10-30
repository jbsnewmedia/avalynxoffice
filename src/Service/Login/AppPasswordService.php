<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Login;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Entity\UserAppPassword;
use AvalynxOffice\Repository\UserAppPasswordRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generates and revokes device/app passwords. The plain password is shown
 * to the user exactly once; only a password_hash() digest is persisted so
 * that the EAS endpoint can verify it with password_verify().
 */
final readonly class AppPasswordService
{
    /** Maximum number of active app passwords per user. */
    public const int MAX_ACTIVE = 10;

    /** Maximum label length (matches the entity column). */
    public const int MAX_LABEL_LENGTH = 100;

    /** Unambiguous lowercase alphabet (no l/o/0/1). */
    private const string ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    private const int GROUPS = 4;
    private const int GROUP_LENGTH = 5;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserAppPasswordRepository $repository,
    ) {
    }

    /**
     * @return list<UserAppPassword>
     */
    public function activeFor(User $user): array
    {
        return $this->repository->findActiveByUser($user);
    }

    public function canCreate(User $user): bool
    {
        return \count($this->repository->findActiveByUser($user)) < self::MAX_ACTIVE;
    }

    /**
     * Creates a new app password and returns the entity together with the
     * plain password (shown once).
     *
     * @return array{0: UserAppPassword, 1: string}
     */
    public function create(User $user, string $label): array
    {
        $label = trim($label);
        if ('' === $label) {
            throw new \InvalidArgumentException('Label must not be empty.');
        }
        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new \InvalidArgumentException('Label is too long.');
        }
        if (!$this->canCreate($user)) {
            throw new \RuntimeException('Maximum number of app passwords reached.');
        }

        $plain = $this->generate();

        $row = new UserAppPassword()
            ->setUser($user)
            ->setLabel($label)
            ->setPasswordHash(password_hash(self::normalize($plain), \PASSWORD_DEFAULT))
            ->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return [$row, $plain];
    }

    /**
     * Revokes a single app password of the user. Returns true when a
     * password was revoked.
     */
    public function revoke(User $user, string $id): bool
    {
        $row = $this->repository->findActiveById($user, $id);
        if (null === $row) {
            return false;
        }

        $row->setRevokedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return true;
    }

    public function revokeAll(User $user): int
    {
        return $this->repository->revokeAll($user);
    }

    /**
     * Generates a password of the form `xxxxx-xxxxx-xxxxx-xxxxx` (100 bit).
     */
    public function generate(): string
    {
        $groups = [];
        $max = \strlen(self::ALPHABET) - 1;

        for ($g = 0; $g < self::GROUPS; ++$g) {
            $group = '';
            for ($i = 0; $i < self::GROUP_LENGTH; ++$i) {
                $group .= self::ALPHABET[random_int(0, $max)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }

    /**
     * Normalizes a password typed on a device: dashes and whitespace are
     * optional, case is ignored.
     */
    public static function normalize(string $password): string
    {
        return mb_strtolower((string) preg_replace('/[\s-]+/', '', $password));
    }
}

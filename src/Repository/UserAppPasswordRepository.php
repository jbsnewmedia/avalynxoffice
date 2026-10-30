<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Entity\UserAppPassword;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserAppPassword>
 */
class UserAppPasswordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAppPassword::class);
    }

    /**
     * All active (not revoked) app passwords of a user, newest first.
     *
     * @return list<UserAppPassword>
     */
    public function findActiveByUser(User $user): array
    {
        /** @var list<UserAppPassword> $result */
        $result = $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->andWhere('p.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findActiveById(User $user, string $id): ?UserAppPassword
    {
        $result = $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->andWhere('p.id = :id')
            ->andWhere('p.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($result instanceof UserAppPassword || null === $result);

        return $result;
    }

    /**
     * Revokes every active app password of the user. Returns the number of
     * revoked passwords.
     */
    public function revokeAll(User $user): int
    {
        $result = $this->createQueryBuilder('p')
            ->update()
            ->set('p.revokedAt', ':now')
            ->where('p.user = :user')
            ->andWhere('p.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
        \assert(is_int($result));

        return $result;
    }
}

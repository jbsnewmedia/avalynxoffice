<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Entity\UserLoginSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserLoginSession>
 */
class UserLoginSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserLoginSession::class);
    }

    /**
     * All active (not revoked) login sessions of a user, newest activity first.
     *
     * @return list<UserLoginSession>
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('s.lastSeenAt', 'DESC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds one active session row of the user by its session token.
     */
    public function findActiveByToken(User $user, string $sessionToken): ?UserLoginSession
    {
        $result = $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.sessionToken = :token')
            ->andWhere('s.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('token', $sessionToken)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($result instanceof UserLoginSession || null === $result);

        return $result;
    }

    /**
     * Revokes every active session of the user except the given token.
     * Returns the number of revoked sessions.
     */
    public function revokeOthers(User $user, string $keepToken): int
    {
        return (int) $this->createQueryBuilder('s')
            ->update()
            ->set('s.revokedAt', ':now')
            ->where('s.user = :user')
            ->andWhere('s.revokedAt IS NULL')
            ->andWhere('s.sessionToken != :keep')
            ->setParameter('user', $user)
            ->setParameter('keep', $keepToken)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}

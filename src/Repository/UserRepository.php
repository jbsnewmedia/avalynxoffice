<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    /**
     * Active Seat definition (APL 1.0 § 1): an account consumes a seat only
     * if it authenticated (web login or device sync) within this window.
     */
    public const int SEAT_ACTIVITY_DAYS = 30;

    /**
     * Successful authentications refresh the seat activity timestamp at most
     * once per interval. Devices authenticate on every sync request, so the
     * throttle keeps the write load negligible.
     */
    private const int AUTH_REFRESH_SECONDS = 3600;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Counts the Active Seats: activated user accounts with a successful
     * authentication within the last 30 calendar days. Deactivated accounts
     * and accounts that never (or not recently) authenticated do not consume
     * a license seat. Called within the seat accounting transaction so that
     * uncommitted concurrent changes are serialized by the license state
     * row lock.
     */
    public function countActiveSeats(\DateTimeImmutable $now): int
    {
        $count = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.active = true')
            ->andWhere('u.lastAuthAt >= :cutoff')
            ->setParameter('cutoff', $now->modify(sprintf('-%d days', self::SEAT_ACTIVITY_DAYS)))
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Records a successful authentication (web login or device sync) as the
     * new seat activity timestamp. The write is skipped while the stored
     * value is still recent, so frequent device authentications do not hit
     * the database on every request.
     */
    public function recordAuthentication(User $user, \DateTimeImmutable $at): void
    {
        $id = $user->getId();

        if (null === $id) {
            return;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE app_user SET last_auth_at = :at'
            .' WHERE id = :id AND (last_auth_at IS NULL OR last_auth_at < :threshold)',
            [
                'at' => $at->format('Y-m-d H:i:s'),
                'id' => $id->toRfc4122(),
                'threshold' => $at->modify(sprintf('-%d seconds', self::AUTH_REFRESH_SECONDS))->format('Y-m-d H:i:s'),
            ],
        );
    }
}

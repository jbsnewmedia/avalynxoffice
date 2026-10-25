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
     * Returns all activated users that carry the administrator role. The
     * roles are stored as a JSON array, so the filter happens in PHP after
     * loading the (small) set of active users.
     *
     * @return list<User>
     */
    public function findActiveAdmins(): array
    {
        $result = $this->createQueryBuilder('u')
            ->where('u.active = true')
            ->getQuery()
            ->getResult();
        \assert(is_array($result));

        return array_values(array_filter(
            $result,
            static fn (mixed $user): bool => $user instanceof User
                && $user->isActive()
                && in_array('ROLE_ADMIN', $user->getRoles(), true),
        ));
    }

    /**
     * Counts the Active Seats: activated users with a successful
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

    /**
     * One page of the admin user table. The search filters on the email
     * address; the order-by map must contain pre-validated field names
     * (e.g. "u.email" => "asc") and is applied as multi-column sort.
     *
     * @param array<string, string> $orderBy
     *
     * @return list<User>
     */
    public function findUsersForAdminList(string $search, array $orderBy, int $offset, int $limit): array
    {
        $queryBuilder = $this->adminListQueryBuilder($search);

        foreach ($orderBy as $field => $direction) {
            $queryBuilder->addOrderBy($field, $direction);
        }

        $result = $queryBuilder
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
        \assert(is_array($result));

        return array_values(array_filter(
            $result,
            static fn (mixed $user): bool => $user instanceof User,
        ));
    }

    /**
     * Number of users matching the admin table search ('' = all users).
     */
    public function countUsersForAdminList(string $search): int
    {
        $count = $this->adminListQueryBuilder($search)
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    private function adminListQueryBuilder(string $search): \Doctrine\ORM\QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('u');

        if ('' !== $search) {
            $queryBuilder
                ->where('LOWER(u.email) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $queryBuilder;
    }
}

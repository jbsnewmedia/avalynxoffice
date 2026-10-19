<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\PasswordResetRequest;
use AvalynxOffice\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetRequest>
 */
class PasswordResetRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetRequest::class);
    }

    /**
     * Finds the newest request (any status) for the given user. Older
     * requests of the same user are considered superseded; the caller may
     * use this to find and clean up stale requests.
     */
    public function findNewestForUser(User $user): ?PasswordResetRequest
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->setParameter('user', $user->getId())
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();
        \assert(is_array($result));

        foreach ($result as $request) {
            if ($request instanceof PasswordResetRequest) {
                return $request;
            }
        }

        return null;
    }

    /**
     * Returns all requests that are still active from the user's point of
     * view: pending, claimed or released. Used to show "your code" again and
     * to enforce one active flow per user.
     *
     * @return list<PasswordResetRequest>
     */
    public function findActiveByUser(User $user): array
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('user', $user->getId())
            ->setParameter('statuses', [
                PasswordResetRequest::STATUS_PENDING,
                PasswordResetRequest::STATUS_CLAIMED,
                PasswordResetRequest::STATUS_RELEASED,
            ])
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
        \assert(is_array($result));

        return array_values(array_filter(
            $result,
            static fn (mixed $request): bool => $request instanceof PasswordResetRequest,
        ));
    }

    /**
     * Finds a request by the plaintext token (the repository hashes it).
     */
    public function findByToken(string $token): ?PasswordResetRequest
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.tokenHash = :hash')
            ->setParameter('hash', hash('sha256', $token))
            ->getQuery()
            ->getResult();
        \assert(is_array($result));

        foreach ($result as $request) {
            if ($request instanceof PasswordResetRequest) {
                return $request;
            }
        }

        return null;
    }

    /**
     * Returns all requests that are still open from the administrator's
     * point of view: pending, claimed or released.
     *
     * @return list<PasswordResetRequest>
     */
    public function findOpenRequests(): array
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', [
                PasswordResetRequest::STATUS_PENDING,
                PasswordResetRequest::STATUS_CLAIMED,
                PasswordResetRequest::STATUS_RELEASED,
            ])
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
        \assert(is_array($result));

        return array_values(array_filter(
            $result,
            static fn (mixed $request): bool => $request instanceof PasswordResetRequest,
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Counts activated user accounts. Deactivated accounts do not consume
     * a license seat. Called within the seat accounting transaction so that
     * uncommitted concurrent activations are serialized by the license
     * state row lock.
     */
    public function countActiveUsers(): int
    {
        $count = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.active = true')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * One page of the admin account table. The search filters on the name
     * and the email address; the order-by map must contain pre-validated
     * field names (e.g. "a.name" => "asc") and is applied as multi-column
     * sort.
     *
     * @param array<string, string> $orderBy
     *
     * @return list<Account>
     */
    public function findAccountsForAdminList(string $search, array $orderBy, int $offset, int $limit): array
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
            static fn (mixed $account): bool => $account instanceof Account,
        ));
    }

    /**
     * Number of accounts matching the admin table search ('' = all accounts).
     */
    public function countAccountsForAdminList(string $search): int
    {
        $count = $this->adminListQueryBuilder($search)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    private function adminListQueryBuilder(string $search): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('a');

        if ('' !== $search) {
            $queryBuilder
                ->where('LOWER(a.name) LIKE :search OR LOWER(a.email) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $queryBuilder;
    }
}

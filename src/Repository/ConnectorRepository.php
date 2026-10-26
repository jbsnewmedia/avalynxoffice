<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\Connector;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Connector>
 */
class ConnectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Connector::class);
    }

    /**
     * One page of the admin connector table. The search filters on the name
     * and the domain; the order-by map must contain pre-validated field
     * names (e.g. "c.name" => "asc") and is applied as multi-column sort.
     *
     * @param array<string, string> $orderBy
     *
     * @return list<Connector>
     */
    public function findConnectorsForAdminList(string $search, array $orderBy, int $offset, int $limit): array
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
            static fn (mixed $connector): bool => $connector instanceof Connector,
        ));
    }

    /**
     * Number of connectors matching the admin table search ('' = all).
     */
    public function countConnectorsForAdminList(string $search): int
    {
        $count = $this->adminListQueryBuilder($search)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    private function adminListQueryBuilder(string $search): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('c');

        if ('' !== $search) {
            $queryBuilder
                ->where('LOWER(c.name) LIKE :search OR LOWER(c.domain) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $queryBuilder;
    }
}

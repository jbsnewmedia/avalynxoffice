<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\SyncRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SyncRun>
 */
class SyncRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncRun::class);
    }

    /**
     * The most recent sync run (any status), null when the service never ran.
     */
    public function findLatest(): ?SyncRun
    {
        $result = $this->createQueryBuilder('r')
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();
        \assert(is_array($result));

        $latest = $result[0] ?? null;

        return $latest instanceof SyncRun ? $latest : null;
    }

    public function save(SyncRun $syncRun): void
    {
        $this->getEntityManager()->persist($syncRun);
        $this->getEntityManager()->flush();
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Email>
 */
class EmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Email::class);
    }

    /**
     * All import keys known for the connector, as a set for fast
     * membership tests (connectors use it to diff the messages on the
     * server against everything already imported).
     *
     * @return array<string, true> import key => true
     */
    public function importKeysForConnector(Connector $connector): array
    {
        $keys = [];
        $rows = $this->createQueryBuilder('e')
            ->select('e.importKey')
            ->where('e.connector = :connector')
            ->setParameter('connector', $connector)
            ->getQuery()
            ->toIterable();

        foreach ($rows as $row) {
            $key = is_array($row) ? ($row['importKey'] ?? null) : null;
            if (is_string($key) && '' !== $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }
}

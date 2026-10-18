<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\RecoveryAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecoveryAddress>
 */
class RecoveryAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecoveryAddress::class);
    }

    /**
     * Returns all recovery addresses that reference the given email address,
     * regardless of the owning user. Used to detect global conflicts when a
     * new address is registered.
     *
     * @param non-empty-string $email
     *
     * @return list<RecoveryAddress>
     */
    public function findByEmail(string $email): array
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.email = :email')
            ->setParameter('email', strtolower($email))
            ->getQuery()
            ->getResult();
        \assert(is_array($result));

        return array_values(array_filter(
            $result,
            static fn (mixed $address): bool => $address instanceof RecoveryAddress,
        ));
    }
}

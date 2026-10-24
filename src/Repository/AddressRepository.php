<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAddress;
use AvalynxOffice\Entity\Address;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Address>
 */
class AddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Address::class);
    }

    /**
     * @return list<Address>
     */
    public function findForAutocomplete(string $search, int $limit = 10): array
    {
        $normalizedSearch = mb_strtolower(trim($search));
        $queryBuilder = $this->createQueryBuilder('address')
            ->orderBy('address.email', 'ASC')
            ->setMaxResults(max(1, min($limit, 25)));

        if ('' !== $normalizedSearch) {
            $queryBuilder
                ->andWhere('LOWER(address.email) LIKE :search OR LOWER(COALESCE(address.name, :empty)) LIKE :search')
                ->setParameter('search', '%'.$normalizedSearch.'%')
                ->setParameter('empty', '');
        }

        /** @var list<Address> $addresses */
        $addresses = $queryBuilder->getQuery()->getResult();

        return $addresses;
    }

    /**
     * Contacts flagged for EAS sync that belong to the given accounts,
     * optionally filtered by a search term across name, company and email.
     *
     * @param list<Account> $accounts
     *
     * @return list<Address>
     */
    public function findContactsForAccounts(array $accounts, string $search = ''): array
    {
        if ([] === $accounts) {
            return [];
        }

        $normalizedSearch = mb_strtolower(trim($search));
        $queryBuilder = $this->createQueryBuilder('address')
            ->andWhere('address.isContact = true')
            ->andWhere('EXISTS (SELECT 1 FROM '.AccountAddress::class.' link WHERE link.address = address AND link.account IN (:accounts))')
            ->setParameter('accounts', $accounts)
            ->orderBy('address.lastname', 'ASC')
            ->addOrderBy('address.firstname', 'ASC')
            ->addOrderBy('address.name', 'ASC')
            ->addOrderBy('address.email', 'ASC');

        if ('' !== $normalizedSearch) {
            $queryBuilder
                ->andWhere('LOWER(address.email) LIKE :search
                    OR LOWER(COALESCE(address.firstname, :empty)) LIKE :search
                    OR LOWER(COALESCE(address.lastname, :empty)) LIKE :search
                    OR LOWER(COALESCE(address.name, :empty)) LIKE :search
                    OR LOWER(COALESCE(address.company, :empty)) LIKE :search')
                ->setParameter('search', '%'.$normalizedSearch.'%')
                ->setParameter('empty', '');
        }

        /** @var list<Address> $addresses */
        $addresses = $queryBuilder->getQuery()->getResult();

        return $addresses;
    }
}

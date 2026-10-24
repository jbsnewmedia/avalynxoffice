<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\CalendarEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarEvent>
 */
class CalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEvent::class);
    }

    /**
     * Events belonging to the given accounts, optionally filtered by a
     * search term across title, location and description, ordered by start.
     *
     * @param list<Account> $accounts
     *
     * @return list<CalendarEvent>
     */
    public function findEventsForAccounts(array $accounts, string $search = ''): array
    {
        if ([] === $accounts) {
            return [];
        }

        $normalizedSearch = mb_strtolower(trim($search));
        $queryBuilder = $this->createQueryBuilder('event')
            ->andWhere('event.account IN (:accounts)')
            ->setParameter('accounts', $accounts)
            ->orderBy('event.startAt', 'ASC')
            ->addOrderBy('event.title', 'ASC');

        if ('' !== $normalizedSearch) {
            $queryBuilder
                ->andWhere('LOWER(event.title) LIKE :search
                    OR LOWER(COALESCE(event.location, :empty)) LIKE :search
                    OR LOWER(COALESCE(event.description, :empty)) LIKE :search')
                ->setParameter('search', '%'.$normalizedSearch.'%')
                ->setParameter('empty', '');
        }

        /** @var list<CalendarEvent> $events */
        $events = $queryBuilder->getQuery()->getResult();

        return $events;
    }

    /**
     * Creates an event from UI form data. The form has already applied the
     * submitted values to the given event.
     */
    public function createFromForm(Account $account, CalendarEvent $event): CalendarEvent
    {
        $event->setAccount($account);

        $this->getEntityManager()->persist($event);
        $this->getEntityManager()->flush();

        return $event;
    }

    /**
     * Persists changes made to an event; the form has already applied the
     * submitted values.
     */
    public function save(CalendarEvent $event): void
    {
        $this->getEntityManager()->flush();
    }

    public function delete(CalendarEvent $event): void
    {
        $this->getEntityManager()->remove($event);
        $this->getEntityManager()->flush();
    }
}

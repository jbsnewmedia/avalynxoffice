<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarEvent;
use App\Entity\Mailbox;
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
     * Events belonging to the given mailboxes, optionally filtered by a
     * search term across title, location and description, ordered by start.
     *
     * @param list<Mailbox> $mailboxes
     *
     * @return list<CalendarEvent>
     */
    public function findEventsForMailboxes(array $mailboxes, string $search = ''): array
    {
        if ([] === $mailboxes) {
            return [];
        }

        $normalizedSearch = mb_strtolower(trim($search));
        $queryBuilder = $this->createQueryBuilder('event')
            ->andWhere('event.mailbox IN (:mailboxes)')
            ->setParameter('mailboxes', $mailboxes)
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
    public function createFromForm(Mailbox $mailbox, CalendarEvent $event): CalendarEvent
    {
        $event->setMailbox($mailbox);

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

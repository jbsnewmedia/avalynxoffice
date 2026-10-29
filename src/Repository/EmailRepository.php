<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Folder;
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

    /**
     * Number of distinct emails linked to the given folders. Emails that
     * sit in several of the folders are counted once.
     *
     * @param array<Folder> $folders
     */
    public function countInFolders(array $folders): int
    {
        if ([] === $folders) {
            return 0;
        }

        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e.id)')
            ->join('e.emailFolders', 'ef')
            ->where('ef.folder IN (:folders)')
            ->setParameter('folders', $folders)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * One page of the folder mail list: the distinct emails linked to the
     * given folders, sorted by the best available date descending (sent,
     * received, created) and finally by id. The account is fetched along
     * so the list does not trigger an extra query per email.
     *
     * @param array<Folder> $folders
     *
     * @return array<Email>
     */
    public function findInFoldersPaginated(array $folders, int $limit, int $offset): array
    {
        if ([] === $folders) {
            return [];
        }

        /** @var array<Email> $emails */
        $emails = $this->createQueryBuilder('e')
            ->addSelect('a')
            ->leftJoin('e.account', 'a')
            ->where('EXISTS (SELECT ef.id FROM AvalynxOffice\Entity\EmailFolder ef WHERE ef.email = e AND ef.folder IN (:folders))')
            ->setParameter('folders', $folders)
            ->orderBy('CASE WHEN e.sentAt IS NOT NULL THEN e.sentAt WHEN e.receivedAt IS NOT NULL THEN e.receivedAt ELSE e.createdAt END', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $emails;
    }

    /**
     * Unread email count per folder id. Folders without unread emails are
     * not part of the result.
     *
     * @param array<Folder> $folders
     *
     * @return array<string, int> folder id => unread count
     */
    public function unreadCountsPerFolder(array $folders): array
    {
        if ([] === $folders) {
            return [];
        }

        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(ef.folder) AS folderId', 'COUNT(e.id) AS unreadCount')
            ->from(EmailFolder::class, 'ef')
            ->join('ef.email', 'e')
            ->where('ef.folder IN (:folders)')
            ->andWhere('e.isRead = false')
            ->setParameter('folders', $folders)
            ->groupBy('ef.folder')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $folderId = is_array($row) ? ($row['folderId'] ?? null) : null;
            $unreadCount = is_array($row) ? ($row['unreadCount'] ?? null) : null;
            if (is_string($folderId) && '' !== $folderId && is_numeric($unreadCount)) {
                $counts[$folderId] = (int) $unreadCount;
            }
        }

        return $counts;
    }

    /**
     * Position of the email within the folder mail list (the number of
     * emails of the set that sort before it), or null when the email is
     * not linked to any of the given folders. Callers use the position to
     * compute the list page that contains the email.
     *
     * @param array<Folder> $folders
     */
    public function rankInFolders(Email $email, array $folders): ?int
    {
        if ([] === $folders) {
            return null;
        }

        $sort = 'COALESCE(x.sentAt, x.receivedAt, x.createdAt)';
        $emailId = $email->getId();
        $sentAt = $email->getSentAt() ?? $email->getReceivedAt() ?? $email->getCreatedAt();
        $base = $this->createQueryBuilder('x')
            ->join('x.emailFolders', 'xef')
            ->where('xef.folder IN (:folders)')
            ->setParameter('folders', $folders);

        $rank = (int) (clone $base)
            ->select('COUNT(DISTINCT x.id)')
            ->andWhere(sprintf('%1$s > :date OR (%1$s = :date AND x.id > :id)', $sort))
            ->setParameter('date', $sentAt)
            ->setParameter('id', $emailId)
            ->getQuery()
            ->getSingleScalarResult();

        $inSet = (int) (clone $base)
            ->select('COUNT(DISTINCT x.id)')
            ->andWhere('x.id = :id')
            ->setParameter('id', $emailId)
            ->getQuery()
            ->getSingleScalarResult();

        return 0 === $inSet ? null : $rank;
    }
}

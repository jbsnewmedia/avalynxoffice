<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailboxConnector;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailboxConnector>
 */
class MailboxConnectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailboxConnector::class);
    }
}

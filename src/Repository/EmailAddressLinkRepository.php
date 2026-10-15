<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailAddressLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailAddressLink>
 */
class EmailAddressLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailAddressLink::class);
    }
}

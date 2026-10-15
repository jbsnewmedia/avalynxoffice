<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailAttachmentLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailAttachmentLink>
 */
class EmailAttachmentLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailAttachmentLink::class);
    }
}

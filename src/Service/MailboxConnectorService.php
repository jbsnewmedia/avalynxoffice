<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MailboxConnector;
use Doctrine\ORM\EntityManagerInterface;

class MailboxConnectorService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(MailboxConnector $connector, bool $isNew = false): void
    {
        if ($isNew) {
            $this->entityManager->persist($connector);
        }

        $this->entityManager->flush();
    }

    public function delete(MailboxConnector $connector): void
    {
        $this->entityManager->remove($connector);
        $this->entityManager->flush();
    }
}

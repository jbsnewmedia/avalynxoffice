<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\MailboxConnector;
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

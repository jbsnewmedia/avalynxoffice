<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Service\Mail\MailRoutingService;
use Doctrine\ORM\EntityManagerInterface;

class ConnectorService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailRoutingService $mailRoutingService,
    ) {
    }

    public function save(Connector $connector, bool $isNew = false, ?Account $formerDefaultAccount = null): void
    {
        if ($isNew) {
            $this->entityManager->persist($connector);
        }

        $this->entityManager->flush();

        if ($formerDefaultAccount !== $connector->getDefaultAccount() && null !== $connector->getDefaultAccount()) {
            $this->mailRoutingService->rerouteDefaultAccount($connector, $formerDefaultAccount, $connector->getDefaultAccount());
        }
    }

    public function delete(Connector $connector): void
    {
        $this->entityManager->remove($connector);
        $this->entityManager->flush();
    }
}

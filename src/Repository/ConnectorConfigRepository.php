<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\ConnectorConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConnectorConfig>
 */
class ConnectorConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConnectorConfig::class);
    }

    /**
     * @return array<string, mixed> the stored config of one plugin for the
     *                              connector, or an empty array
     */
    public function getConfig(Connector $connector, string $pluginKey): array
    {
        $config = $this->findOneBy(['connector' => $connector, 'pluginKey' => $pluginKey]);
        if (!$config instanceof ConnectorConfig) {
            return [];
        }

        return $config->getConfig();
    }
}

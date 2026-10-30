<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\ConnectorConfig;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use AvalynxOffice\Service\Security\SecretCrypto;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists the JSON settings of connector plugins per connector. Secret
 * keys declared by the plugin are encrypted at rest.
 */
class ConnectorConfigService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectorConfigRepository $repository,
        private readonly ConnectorPluginRegistry $pluginRegistry,
        private readonly SecretCrypto $secretCrypto,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public function save(Connector $connector, string $pluginKey, array $config): ConnectorConfig
    {
        $entity = $this->repository->findOneBy(['connector' => $connector, 'pluginKey' => $pluginKey]);
        if (!$entity instanceof ConnectorConfig) {
            $entity = new ConnectorConfig();
            $entity->setConnector($connector);
            $entity->setPluginKey($pluginKey);
            $this->entityManager->persist($entity);
        }
        $plugin = $this->pluginRegistry->get($pluginKey);
        if (null !== $plugin) {
            $config = $this->secretCrypto->encryptKeys($config, $plugin->getSecretConfigKeys());
        }
        $entity->setConfig($config);
        $this->entityManager->flush();

        return $entity;
    }

    public function delete(Connector $connector, string $pluginKey): void
    {
        $entity = $this->repository->findOneBy(['connector' => $connector, 'pluginKey' => $pluginKey]);
        if (!$entity instanceof ConnectorConfig) {
            return;
        }
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Entity;

use AvalynxOffice\Repository\ConnectorConfigRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Stores the settings a connector plugin needs for one connector (domain)
 * as JSON. One row per connector and plugin key.
 */
#[ORM\Entity(repositoryClass: ConnectorConfigRepository::class)]
#[ORM\Table(name: 'connector_config')]
#[ORM\UniqueConstraint(name: 'uniq_connector_config', columns: ['connector_id', 'plugin_key'])]
#[ORM\HasLifecycleCallbacks]
class ConnectorConfig
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\ManyToOne(targetEntity: Connector::class)]
    #[ORM\JoinColumn(name: 'connector_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Connector $connector;
    #[ORM\Column(name: 'plugin_key', type: 'string', length: 100)]
    private string $pluginKey;
    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $config = [];

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getConnector(): Connector
    {
        return $this->connector;
    }

    public function setConnector(Connector $connector): self
    {
        $this->connector = $connector;

        return $this;
    }

    public function getPluginKey(): string
    {
        return $this->pluginKey;
    }

    public function setPluginKey(string $pluginKey): self
    {
        $this->pluginKey = $pluginKey;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }
}

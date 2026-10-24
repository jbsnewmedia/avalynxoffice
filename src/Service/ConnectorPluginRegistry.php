<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Plugin\Contract\ConnectorFetcherInterface;
use AvalynxOffice\Plugin\Contract\ConnectorMailerInterface;
use AvalynxOffice\Plugin\Contract\ConnectorPluginInterface;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Registry of all connector plugins (tagged with "avalynxoffice.connector_plugin").
 * Resolves the stored per-connector plugin config and the aggregated
 * input/output/status state of a connector.
 */
class ConnectorPluginRegistry
{
    /**
     * @var list<ConnectorPluginInterface>
     */
    private readonly array $plugins;

    /**
     * @param iterable<int, ConnectorPluginInterface> $plugins
     */
    public function __construct(
        iterable $plugins,
        private readonly ConnectorConfigRepository $configRepository,
    ) {
        $collected = [];
        foreach ($plugins as $plugin) {
            $collected[] = $plugin;
        }
        usort($collected, static fn (ConnectorPluginInterface $left, ConnectorPluginInterface $right): int => $left->getKey() <=> $right->getKey());
        $this->plugins = $collected;
    }

    /**
     * @return list<ConnectorPluginInterface>
     */
    public function all(): array
    {
        return $this->plugins;
    }

    public function get(string $key): ?ConnectorPluginInterface
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->getKey() === $key) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed> the stored plugin config of the connector
     *                              (empty array when not configured yet)
     */
    public function getConfig(Connector $connector, ConnectorPluginInterface $plugin): array
    {
        return $this->configRepository->getConfig($connector, $plugin->getKey());
    }

    /**
     * Whether any plugin has a fully configured input (Eingang) for the
     * given connector.
     */
    public function isInputConfigured(Connector $connector): bool
    {
        return array_any($this->plugins, fn ($plugin) => $plugin->supportsInput() && $plugin->isInputConfigured($this->getConfig($connector, $plugin)));
    }

    /**
     * Whether any plugin has a fully configured output (Ausgabe) for the
     * given connector.
     */
    public function isOutputConfigured(Connector $connector): bool
    {
        return array_any($this->plugins, fn ($plugin) => $plugin->supportsOutput() && $plugin->isOutputConfigured($this->getConfig($connector, $plugin)));
    }

    /**
     * Merged sync status of all plugins for the connector: first non-null
     * value per module wins.
     *
     * @return array{mails: ?string, contacts: ?string, calendar: ?string}
     */
    public function getStatus(Connector $connector): array
    {
        $status = ['mails' => null, 'contacts' => null, 'calendar' => null];
        foreach ($this->plugins as $plugin) {
            $pluginStatus = $plugin->getStatus($connector, $this->getConfig($connector, $plugin));
            foreach ($status as $module => $value) {
                if (null === $value && null !== $pluginStatus[$module]) {
                    $status[$module] = $pluginStatus[$module];
                }
            }
        }

        return $status;
    }

    /**
     * Builds a mailer from the first plugin that has a configured output
     * for the given connector.
     */
    public function createMailer(Connector $connector): ?MailerInterface
    {
        foreach ($this->plugins as $plugin) {
            if (!$plugin instanceof ConnectorMailerInterface || !$plugin->supportsOutput()) {
                continue;
            }
            $config = $this->getConfig($connector, $plugin);
            if ($plugin->isOutputConfigured($config)) {
                return $plugin->createMailer($connector, $config);
            }
        }

        return null;
    }

    /**
     * @return list<array{plugin: ConnectorFetcherInterface&ConnectorPluginInterface, config: array<string, mixed>}> all
     *                                                                                                               plugins that can fetch data and are configured for the connector
     */
    public function getFetchers(Connector $connector): array
    {
        $fetchers = [];
        foreach ($this->plugins as $plugin) {
            if (!$plugin instanceof ConnectorFetcherInterface || !$plugin->supportsInput()) {
                continue;
            }
            $config = $this->getConfig($connector, $plugin);
            if (!$plugin->isInputConfigured($config)) {
                continue;
            }
            $fetchers[] = ['plugin' => $plugin, 'config' => $config];
        }

        return $fetchers;
    }
}

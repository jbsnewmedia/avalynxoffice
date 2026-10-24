<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Plugin\Contract;

use AvalynxOffice\Entity\Connector;
use Symfony\Component\Form\FormTypeInterface;

/**
 * Contract for connector plugins. A connector plugin bundles everything a
 * technical connector needs (fetching, sending, ...) and ships its own
 * settings form that is rendered in the connector settings dialog.
 * The plugin settings are stored per connector as JSON in ConnectorConfig.
 */
interface ConnectorPluginInterface
{
    /**
     * Machine name of the plugin, used as storage key (e.g. "pop3").
     */
    public function getKey(): string;

    /**
     * Translation key of the display name shown in the settings dialog.
     */
    public function getName(): string;

    /**
     * Whether the plugin can receive data (Eingang).
     */
    public function supportsInput(): bool;

    /**
     * Whether the plugin can send data (Ausgabe).
     */
    public function supportsOutput(): bool;

    /**
     * Form type of the plugin settings. The form works on the raw
     * config array (data_class = null), field names must match the
     * config array keys.
     *
     * @return class-string<FormTypeInterface<object>>
     */
    public function getSettingsFormType(): string;

    /**
     * Twig template (with namespace, e.g. "@Pop3Connector/...") that renders
     * the settings form. Receives the rendered form as "form" variable.
     */
    public function getSettingsTemplate(): string;

    /**
     * Whether the input (Eingang) is fully configured for the given config.
     *
     * @param array<string, mixed> $config
     */
    public function isInputConfigured(array $config): bool;

    /**
     * Whether the output (Ausgabe) is fully configured for the given config.
     *
     * @param array<string, mixed> $config
     */
    public function isOutputConfigured(array $config): bool;

    /**
     * Sync status per module: how much of the data is already local
     * ("120 von 251"), or null when the module has no status.
     *
     * @param array<string, mixed> $config
     *
     * @return array{mails: ?string, contacts: ?string, calendar: ?string}
     */
    public function getStatus(Connector $connector, array $config): array;
}

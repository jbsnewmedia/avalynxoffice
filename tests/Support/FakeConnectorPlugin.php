<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Support;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Plugin\Contract\ConnectorCalendarFetcherInterface;
use AvalynxOffice\Plugin\Contract\ConnectorContactFetcherInterface;
use AvalynxOffice\Plugin\Contract\ConnectorFetcherInterface;
use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use AvalynxOffice\Plugin\Contract\ConnectorMailerInterface;
use AvalynxOffice\Plugin\Contract\ConnectorPluginInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Test double for a connector plugin with configurable capabilities.
 */
class FakeConnectorPlugin implements ConnectorPluginInterface, ConnectorFetcherInterface, ConnectorContactFetcherInterface, ConnectorCalendarFetcherInterface, ConnectorMailerInterface
{
    /**
     * @var list<array{0: Connector, 1: array<string, mixed>, 2: ConnectorFetchRequest}>
     */
    public array $fetchCalls = [];
    /**
     * @var array{imported: int, skipped: int, errors: int}
     */
    public array $fetchResult = ['imported' => 0, 'skipped' => 0, 'errors' => 0];
    /**
     * @var list<array{0: Connector, 1: array<string, mixed>, 2: ConnectorFetchRequest}>
     */
    public array $contactFetchCalls = [];
    /**
     * @var array{imported: int, skipped: int, errors: int}
     */
    public array $contactFetchResult = ['imported' => 0, 'skipped' => 0, 'errors' => 0];
    /**
     * @var list<array{0: Connector, 1: array<string, mixed>, 2: ConnectorFetchRequest}>
     */
    public array $calendarFetchCalls = [];
    /**
     * @var array{imported: int, skipped: int, errors: int}
     */
    public array $calendarFetchResult = ['imported' => 0, 'skipped' => 0, 'errors' => 0];
    /**
     * @var list<Connector>
     */
    public array $mailerCalls = [];

    public function __construct(
        public string $key = 'fake',
        public bool $input = true,
        public bool $output = true,
        public bool $inputConfigured = false,
        public bool $outputConfigured = false,
        public ?MailerInterface $mailer = null,
        /** @var list<string> */
        public array $secretConfigKeys = [],
    ) {
    }

    #[\Override]
    public function getKey(): string
    {
        return $this->key;
    }

    #[\Override]
    public function getName(): string
    {
        return 'connector.plugin.'.$this->key;
    }

    #[\Override]
    public function supportsInput(): bool
    {
        return $this->input;
    }

    #[\Override]
    public function supportsOutput(): bool
    {
        return $this->output;
    }

    #[\Override]
    public function getSettingsFormType(): string
    {
        return FakeConnectorSettingsType::class;
    }

    #[\Override]
    public function getSettingsTemplate(): string
    {
        return '@FakeConnector/settings_form.html.twig';
    }

    #[\Override]
    public function getSecretConfigKeys(): array
    {
        return $this->secretConfigKeys;
    }

    #[\Override]
    public function isInputConfigured(array $config): bool
    {
        return $this->inputConfigured;
    }

    #[\Override]
    public function isOutputConfigured(array $config): bool
    {
        return $this->outputConfigured;
    }

    #[\Override]
    public function getStatus(Connector $connector, array $config): array
    {
        return ['mails' => null, 'contacts' => null, 'calendar' => null];
    }

    #[\Override]
    public function fetch(Connector $connector, array $config, ConnectorFetchRequest $request): array
    {
        $this->fetchCalls[] = [$connector, $config, $request];

        return $this->fetchResult;
    }

    #[\Override]
    public function fetchContacts(Connector $connector, array $config, ConnectorFetchRequest $request): array
    {
        $this->contactFetchCalls[] = [$connector, $config, $request];

        return $this->contactFetchResult;
    }

    #[\Override]
    public function fetchCalendar(Connector $connector, array $config, ConnectorFetchRequest $request): array
    {
        $this->calendarFetchCalls[] = [$connector, $config, $request];

        return $this->calendarFetchResult;
    }

    #[\Override]
    public function createMailer(Connector $connector, array $config): MailerInterface
    {
        $this->mailerCalls[] = $connector;

        if (!$this->mailer instanceof MailerInterface) {
            throw new \LogicException('No mailer configured on the fake plugin.');
        }

        return $this->mailer;
    }
}

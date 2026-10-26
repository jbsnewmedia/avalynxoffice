<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\ExchangeConnectorBundle\Plugin;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\CalendarEvent;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Plugin\Contract\ConnectorCalendarFetcherInterface;
use AvalynxOffice\Plugin\Contract\ConnectorContactFetcherInterface;
use AvalynxOffice\Plugin\Contract\ConnectorFetcherInterface;
use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use AvalynxOffice\Plugin\Contract\ConnectorPluginInterface;
use Doctrine\ORM\EntityManagerInterface;
use JBSNewMedia\ExchangeConnectorBundle\Form\ExchangeConnectorSettingsType;
use JBSNewMedia\ExchangeConnectorBundle\Service\EwsCalendarFetcher;
use JBSNewMedia\ExchangeConnectorBundle\Service\EwsContactFetcher;
use JBSNewMedia\ExchangeConnectorBundle\Service\EwsMailFetcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Exchange connector plugin: syncs mails, contacts and calendar
 * entries of one Exchange 2013 mailbox via EWS (Eingang). The data is only
 * pulled; on request the objects are deleted on the Exchange server after a
 * successful import ("Nach Abruf löschen" option).
 */
class ExchangeConnectorPlugin implements ConnectorPluginInterface, ConnectorFetcherInterface, ConnectorContactFetcherInterface, ConnectorCalendarFetcherInterface
{
    final public const string KEY = 'exchange';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly EwsMailFetcher $mailFetcher,
        private readonly EwsContactFetcher $contactFetcher,
        private readonly EwsCalendarFetcher $calendarFetcher,
    ) {
    }

    #[\Override]
    public function getKey(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function getName(): string
    {
        return 'connector.plugin.exchange';
    }

    #[\Override]
    public function supportsInput(): bool
    {
        return true;
    }

    #[\Override]
    public function supportsOutput(): bool
    {
        return false;
    }

    #[\Override]
    public function getSettingsFormType(): string
    {
        return ExchangeConnectorSettingsType::class;
    }

    #[\Override]
    public function getSettingsTemplate(): string
    {
        return '@ExchangeConnector/exchange_connector/settings_form.html.twig';
    }

    #[\Override]
    public function isInputConfigured(array $config): bool
    {
        return self::isFilled($config, 'ewsHost')
            && self::isFilled($config, 'ewsUsername')
            && self::isFilled($config, 'ewsPassword');
    }

    #[\Override]
    public function isOutputConfigured(array $config): bool
    {
        return false;
    }

    #[\Override]
    public function getStatus(Connector $connector, array $config): array
    {
        $mails = self::statusOf(
            $this->translator,
            (int) $this->entityManager->getRepository(Email::class)->count(['connector' => $connector]),
            self::intValue($config, 'mails_total'),
        );
        $contacts = self::statusOf(
            $this->translator,
            $this->countLocalContacts(),
            self::intValue($config, 'contacts_total'),
        );
        $calendar = self::statusOf(
            $this->translator,
            $this->countLocalCalendar(),
            self::intValue($config, 'calendar_total'),
        );

        return ['mails' => $mails, 'contacts' => $contacts, 'calendar' => $calendar];
    }

    #[\Override]
    public function fetch(Connector $connector, array $config, ConnectorFetchRequest $request): array
    {
        return $this->mailFetcher->fetch($connector, $config, $request);
    }

    #[\Override]
    public function fetchContacts(Connector $connector, array $config, ConnectorFetchRequest $request): array
    {
        return $this->contactFetcher->fetchContacts($connector, $config, $request);
    }

    #[\Override]
    public function fetchCalendar(Connector $connector, array $config, ConnectorFetchRequest $request): array
    {
        return $this->calendarFetcher->fetchCalendar($connector, $config, $request);
    }

    private function countLocalContacts(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Address::class, 'a')
            ->where('a.isContact = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countLocalCalendar(): int
    {
        return (int) $this->entityManager->getRepository(CalendarEvent::class)->count([]);
    }

    private static function statusOf(TranslatorInterface $translator, int $local, int $total): ?string
    {
        if ($total > 0) {
            return $translator->trans('connector.status.of', ['%local%' => $local, '%total%' => $total]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function isFilled(array $config, string $key): bool
    {
        $value = $config[$key] ?? null;

        return is_string($value) && '' !== trim($value);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function intValue(array $config, string $key): int
    {
        $value = $config[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}

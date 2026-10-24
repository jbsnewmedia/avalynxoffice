<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\Pop3ConnectorBundle\Plugin;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Plugin\Contract\ConnectorFetcherInterface;
use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use AvalynxOffice\Plugin\Contract\ConnectorMailerInterface;
use AvalynxOffice\Plugin\Contract\ConnectorPluginInterface;
use Doctrine\ORM\EntityManagerInterface;
use JBSNewMedia\Pop3ConnectorBundle\Form\Pop3ConnectorSettingsType;
use JBSNewMedia\Pop3ConnectorBundle\Service\Pop3Fetcher;
use JBSNewMedia\Pop3ConnectorBundle\Service\SmtpMailerFactory;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The POP3 connector plugin: fetches all mail of a domain via POP3
 * (Eingang) and sends via SMTP (Ausgabe).
 */
class Pop3ConnectorPlugin implements ConnectorPluginInterface, ConnectorFetcherInterface, ConnectorMailerInterface
{
    final public const string KEY = 'pop3';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Pop3Fetcher $fetcher,
        private readonly SmtpMailerFactory $mailerFactory,
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
        return 'connector.plugin.pop3';
    }

    #[\Override]
    public function supportsInput(): bool
    {
        return true;
    }

    #[\Override]
    public function supportsOutput(): bool
    {
        return true;
    }

    #[\Override]
    public function getSettingsFormType(): string
    {
        return Pop3ConnectorSettingsType::class;
    }

    #[\Override]
    public function getSettingsTemplate(): string
    {
        return '@Pop3Connector/pop3_connector/settings_form.html.twig';
    }

    #[\Override]
    public function isInputConfigured(array $config): bool
    {
        return self::isFilled($config, 'pop3Host')
            && self::isFilled($config, 'pop3Username')
            && self::isFilled($config, 'pop3Password');
    }

    #[\Override]
    public function isOutputConfigured(array $config): bool
    {
        return self::isFilled($config, 'smtpHost');
    }

    #[\Override]
    public function getStatus(Connector $connector, array $config): array
    {
        $local = (int) $this->entityManager->getRepository(Email::class)->count(['connector' => $connector]);
        $total = self::intValue($config, 'server_total');
        $mails = null;
        if ($total > 0) {
            $mails = $this->translator->trans('connector.status.of', ['%local%' => $local, '%total%' => $total]);
        }

        return ['mails' => $mails, 'contacts' => null, 'calendar' => null];
    }

    #[\Override]
    public function fetch(Connector $connector, array $config, ConnectorFetchRequest $request): array
    {
        return $this->fetcher->fetch($connector, $config, $request);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[\Override]
    public function createMailer(Connector $connector, array $config): MailerInterface
    {
        return $this->mailerFactory->fromConfig($config);
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

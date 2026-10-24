<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Plugin\Pop3ConnectorBundle\Service;

use JBSNewMedia\Pop3ConnectorBundle\Service\SmtpMailerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

final class SmtpMailerFactoryTest extends TestCase
{
    public function testCreatesMailerForPlainSmtpConfig(): void
    {
        $mailer = (new SmtpMailerFactory())->fromConfig($this->config([
            'smtpUsername' => 'user@example.com',
            'smtpPassword' => 'secret pass',
            'smtpSecurity' => 'tls',
        ]));

        self::assertInstanceOf(MailerInterface::class, $mailer);
    }

    public function testCreatesMailerForSslConfigWithDefaultPort(): void
    {
        $mailer = (new SmtpMailerFactory())->fromConfig($this->config(['smtpSecurity' => 'ssl']));

        self::assertInstanceOf(MailerInterface::class, $mailer);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'smtpHost' => 'mail.example.com',
            'smtpPort' => null,
            'smtpUsername' => null,
            'smtpPassword' => null,
            'smtpSecurity' => null,
        ], $overrides);
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Mail;

use AvalynxOffice\Entity\MailboxConnector;
use AvalynxOffice\Service\Mail\SmtpMailerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

final class SmtpMailerFactoryTest extends TestCase
{
    public function testCreatesMailerForPlainSmtpConnector(): void
    {
        $connector = (new MailboxConnector())
            ->setName('SMTP')
            ->setEmail('smtp@example.com')
            ->setSmtpHost('mail.example.com')
            ->setSmtpUsername('user@example.com')
            ->setSmtpPassword('secret pass')
            ->setSmtpSecurity('tls');

        $mailer = (new SmtpMailerFactory())->fromConnector($connector);

        self::assertInstanceOf(MailerInterface::class, $mailer);
    }

    public function testCreatesMailerForSslConnectorWithDefaultPort(): void
    {
        $connector = (new MailboxConnector())
            ->setName('SMTP')
            ->setEmail('smtp@example.com')
            ->setSmtpHost('mail.example.com')
            ->setSmtpSecurity('ssl');

        $mailer = (new SmtpMailerFactory())->fromConnector($connector);

        self::assertInstanceOf(MailerInterface::class, $mailer);
    }
}

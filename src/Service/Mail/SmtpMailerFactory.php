<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\MailboxConnector;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

class SmtpMailerFactory
{
    public function fromConnector(MailboxConnector $connector): MailerInterface
    {
        $user = urlencode($connector->getSmtpUsername() ?? '');
        $pass = urlencode($connector->getSmtpPassword() ?? '');
        $host = (string) $connector->getSmtpHost();
        $port = $connector->getSmtpPort() ?: 25;
        $scheme = 'ssl' === $connector->getSmtpSecurity() ? 'smtps' : 'smtp';
        $dsn = sprintf('%s://%s:%s@%s:%d', $scheme, $user, $pass, $host, $port);

        return new Mailer(Transport::fromDsn($dsn));
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Mail;

use AvalynxOffice\Entity\MailboxConnector;
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

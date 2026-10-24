<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\Pop3ConnectorBundle\Service;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

class SmtpMailerFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public function fromConfig(array $config): MailerInterface
    {
        $user = urlencode(self::stringValue($config, 'smtpUsername'));
        $pass = urlencode(self::stringValue($config, 'smtpPassword'));
        $host = self::stringValue($config, 'smtpHost');
        $port = self::intValue($config, 'smtpPort') ?: 25;
        $scheme = 'ssl' === self::stringValue($config, 'smtpSecurity') ? 'smtps' : 'smtp';
        $dsn = sprintf('%s://%s:%s@%s:%d', $scheme, $user, $pass, $host, $port);

        return new Mailer(Transport::fromDsn($dsn));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function stringValue(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_string($value) ? $value : '';
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

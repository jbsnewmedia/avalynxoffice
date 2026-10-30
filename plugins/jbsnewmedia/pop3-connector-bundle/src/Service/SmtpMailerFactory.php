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
use Symfony\Component\Mailer\Transport\Dsn;

class SmtpMailerFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public function fromConfig(array $config): MailerInterface
    {
        $user = self::stringValue($config, 'smtpUsername');
        $pass = self::stringValue($config, 'smtpPassword');
        $host = self::validateHost(self::stringValue($config, 'smtpHost'));
        $port = self::validatePort(self::intValue($config, 'smtpPort') ?: 25);
        $security = strtolower(trim(self::stringValue($config, 'smtpSecurity')));

        $scheme = 'ssl' === $security ? 'smtps' : 'smtp';
        $options = [];
        if ('tls' === $security) {
            $options['require_tls'] = true;
        }

        $dsn = new Dsn($scheme, $host, '' !== $user ? $user : null, '' !== $pass ? $pass : null, $port, $options);

        return new Mailer((new Transport(Transport::getDefaultFactories()))->fromDsnObject($dsn));
    }

    public static function validateHost(string $host): string
    {
        $host = trim($host);
        if ('' === $host) {
            throw new \InvalidArgumentException('SMTP host must not be empty.');
        }
        if (1 === preg_match('/[\/?#@\s\\\\]/', $host)) {
            throw new \InvalidArgumentException(sprintf('Invalid SMTP host "%s".', $host));
        }
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']') && false !== filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $host;
        }
        if (false === filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new \InvalidArgumentException(sprintf('Invalid SMTP host "%s".', $host));
        }

        return $host;
    }

    public static function validatePort(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(sprintf('Invalid SMTP port %d.', $port));
        }

        return $port;
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

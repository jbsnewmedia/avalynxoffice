<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\ExchangeConnectorBundle\Service;

use garethp\ews\API\ExchangeWebServices;

/**
 * Builds the typed EWS client from the stored plugin config of a connector.
 * The Exchange server is addressed via its host name (or a full URL), the
 * endpoint "https://<host>/EWS/Exchange.asmx" is built by the library.
 */
final class EwsClientFactory
{
    public const string VERSION_2013 = 'exchange2013';

    public const string VERSION_2013_SP1 = 'exchange2013_sp1';

    /**
     * @param array<string, mixed> $config
     */
    public function create(array $config): EwsApiClient
    {
        return EwsApiClient::create(
            self::stringValue($config, 'ewsHost'),
            self::stringValue($config, 'ewsUsername'),
            self::stringValue($config, 'ewsPassword'),
            self::mapVersion(self::stringValue($config, 'ewsVersion')),
        );
    }

    private static function mapVersion(string $configured): string
    {
        return match ($configured) {
            self::VERSION_2013 => ExchangeWebServices::VERSION_2013,
            default => ExchangeWebServices::VERSION_2013_SP1,
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function stringValue(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}

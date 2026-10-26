<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Plugin\Contract;

use AvalynxOffice\Entity\Connector;

/**
 * Capability interface for connector plugins that fetch calendar entries
 * from a remote system (Eingang), e.g. an Exchange calendar.
 */
interface ConnectorCalendarFetcherInterface
{
    /**
     * Fetches the calendar entries the remote system offers for the given
     * connector.
     *
     * @param array<string, mixed>  $config  stored plugin config of the connector
     * @param ConnectorFetchRequest $request fetch options (limit, output, ...)
     *
     * @return array{imported: int, skipped: int, errors: int}
     */
    public function fetchCalendar(Connector $connector, array $config, ConnectorFetchRequest $request): array;
}

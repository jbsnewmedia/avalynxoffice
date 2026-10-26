<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Plugin\Contract;

use AvalynxOffice\Entity\Connector;

/**
 * Capability interface for connector plugins that fetch contacts from a
 * remote system (Eingang), e.g. an Exchange address book.
 */
interface ConnectorContactFetcherInterface
{
    /**
     * Fetches the contacts the remote system offers for the given connector.
     *
     * @param array<string, mixed>  $config  stored plugin config of the connector
     * @param ConnectorFetchRequest $request fetch options (limit, output, ...)
     *
     * @return array{imported: int, skipped: int, errors: int}
     */
    public function fetchContacts(Connector $connector, array $config, ConnectorFetchRequest $request): array;
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Plugin\Contract;

use AvalynxOffice\Entity\Connector;

/**
 * Capability interface for connector plugins that fetch data from a remote
 * system (Eingang), e.g. mail retrieval via POP3.
 */
interface ConnectorFetcherInterface
{
    /**
     * Fetches everything the remote system offers for the given connector.
     *
     * @param array<string, mixed>  $config  stored plugin config of the connector
     * @param ConnectorFetchRequest $request fetch options (limit, output, ...)
     *
     * @return array{imported: int, skipped: int, errors: int}
     */
    public function fetch(Connector $connector, array $config, ConnectorFetchRequest $request): array;
}

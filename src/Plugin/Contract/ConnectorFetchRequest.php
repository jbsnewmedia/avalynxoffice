<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Plugin\Contract;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Options for a connector fetch run.
 */
class ConnectorFetchRequest
{
    public ?int $limit = null;
    public ?bool $deleteAfter = null;
    public bool $oldestFirst = false;
    public ?OutputInterface $output = null;
    public ?string $errorLogPath = null;
}

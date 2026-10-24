<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\Pop3ConnectorBundle\Service;

use Laminas\Mail\Storage\Pop3 as Pop3Storage;

class Pop3StorageFactory
{
    /**
     * @param array{host: string, user: string, password: string, port: ?int, ssl: ?string} $config
     */
    public function create(array $config): Pop3Storage
    {
        return new Pop3Storage($config);
    }
}

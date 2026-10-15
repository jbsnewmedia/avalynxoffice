<?php

declare(strict_types=1);

namespace App\Service\Mail;

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

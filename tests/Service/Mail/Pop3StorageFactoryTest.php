<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\Pop3StorageFactory;
use Laminas\Mail\Protocol\Exception\RuntimeException;
use PHPUnit\Framework\TestCase;

final class Pop3StorageFactoryTest extends TestCase
{
    public function testCreatePropagatesConnectionFailures(): void
    {
        $factory = new Pop3StorageFactory();
        $this->expectException(RuntimeException::class);

        $factory->create([
            'host' => 'mail.example.com',
            'user' => 'mailer@example.com',
            'password' => 'secret',
            'port' => 110,
            'ssl' => null,
        ]);
    }
}

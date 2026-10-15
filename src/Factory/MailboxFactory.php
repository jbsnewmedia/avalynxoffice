<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Mailbox;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Mailbox>
 */
final class MailboxFactory extends PersistentObjectFactory
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $faker = self::faker();

        return [
            'name' => $faker->company(),
            'email' => $faker->unique()->companyEmail(),
        ];
    }

    #[\Override]
    public static function class(): string
    {
        return Mailbox::class;
    }
}

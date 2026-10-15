<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Address;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Address>
 */
final class AddressFactory extends PersistentObjectFactory
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $faker = self::faker();

        return [
            'email' => $faker->unique()->safeEmail(),
            'name' => $faker->optional(0.7)->name(),
        ];
    }

    #[\Override]
    public static function class(): string
    {
        return Address::class;
    }
}

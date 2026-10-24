<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Factory;

use AvalynxOffice\Entity\Account;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Account>
 */
final class AccountFactory extends PersistentObjectFactory
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
        return Account::class;
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Factory;

use AvalynxOffice\Entity\Folder;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Folder>
 */
final class FolderFactory extends PersistentObjectFactory
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $faker = self::faker();

        return [
            'functions' => [],
            'name' => ucfirst($faker->unique()->word()),
        ];
    }

    #[\Override]
    public static function class(): string
    {
        return Folder::class;
    }
}

<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Folder;
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

<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Body;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Body>
 */
final class BodyFactory extends PersistentObjectFactory
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $faker = self::faker();

        return [
            'content' => $faker->paragraphs(3, true),
        ];
    }

    #[\Override]
    public static function class(): string
    {
        return Body::class;
    }
}

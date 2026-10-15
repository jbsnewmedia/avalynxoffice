<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Email;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Email>
 */
final class EmailFactory extends PersistentObjectFactory
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $faker = self::faker();
        $receivedAtMutable = $faker->optional(0.9)->dateTimeBetween('-30 days', 'now');
        $receivedAt = null;
        if ($receivedAtMutable instanceof \DateTime) {
            $receivedAt = \DateTimeImmutable::createFromMutable($receivedAtMutable);
        }

        return [
            'subject' => $faker->optional(0.8)->sentence(6),
            'previewBody' => $faker->optional(0.9)->text(200),
            'receivedAt' => $receivedAt,
        ];
    }

    #[\Override]
    public static function class(): string
    {
        return Email::class;
    }
}

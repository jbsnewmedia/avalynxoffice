<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Attachment;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Attachment>
 */
final class AttachmentFactory extends PersistentObjectFactory
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $faker = self::faker();
        $types = [
            ['ext' => 'pdf',  'mime' => 'application/pdf'],
            ['ext' => 'doc',  'mime' => 'application/msword'],
            ['ext' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['ext' => 'xls',  'mime' => 'application/vnd.ms-excel'],
            ['ext' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['ext' => 'csv',  'mime' => 'text/csv'],
            ['ext' => 'ppt',  'mime' => 'application/vnd.ms-powerpoint'],
            ['ext' => 'pptx', 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            ['ext' => 'jpg',  'mime' => 'image/jpeg'],
            ['ext' => 'jpeg', 'mime' => 'image/jpeg'],
            ['ext' => 'png',  'mime' => 'image/png'],
            ['ext' => 'txt',  'mime' => 'text/plain'],
            ['ext' => 'zip',  'mime' => 'application/zip'],
        ];
        $type = $types[array_rand($types)];
        $ext = (string) $type['ext'];
        $mime = (string) $type['mime'];
        $baseRaw = $faker->unique()->words(random_int(1, 3), true);
        $baseString = is_array($baseRaw) ? implode('-', array_filter($baseRaw, is_string(...))) : (string) $baseRaw;
        $base = self::slugifyBaseName($baseString);
        $name = $base.'.'.$ext;
        $extString = (string) $ext;
        $size = match ($extString) {
            'jpg', 'jpeg', 'png' => $faker->numberBetween(8_192, 65_536),
            'pdf' => $faker->numberBetween(4_096, 65_536),
            'zip' => $faker->numberBetween(12_288, 65_536),
            default => $faker->numberBetween(1_024, 65_536),
        };

        return [
            'name' => $name,
            'size' => $size,
            'mimeType' => $mime,
            'sha1' => $faker->sha1(),
            'filePath' => 'pending/'.$name,
        ];
    }

    #[\Override]
    public static function class(): string
    {
        return Attachment::class;
    }

    public static function slugifyBaseName(string $raw): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $raw) ?? '', '-'));
        if ('' === $base) {
            $base = 'file';
        }

        return $base;
    }
}

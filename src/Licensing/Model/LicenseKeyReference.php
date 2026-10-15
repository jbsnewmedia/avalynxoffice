<?php

declare(strict_types=1);

namespace App\Licensing\Model;

/**
 * Reference to a license key together with its configured source.
 */
final readonly class LicenseKeyReference
{
    public function __construct(
        public string $key,
        public LicenseSource $source,
    ) {
    }

    public function preview(): string
    {
        return mb_substr($this->key, 0, 24).'…';
    }
}

<?php

declare(strict_types=1);

namespace App\Licensing\Provider;

use App\Licensing\Model\LicenseKeyReference;

/**
 * Provides license keys from the AVALYNXOFFICE_LICENSE_KEYS environment variable.
 * Comma and newline are valid separators.
 */
final readonly class EnvLicenseKeyProvider implements LicenseKeyProviderInterface
{
    public function __construct(
        private string $keys,
    ) {
    }

    #[\Override]
    public function getLicenseKeys(): array
    {
        $references = [];
        foreach ($this->split($this->keys) as $key) {
            $references[] = new LicenseKeyReference($key, \App\Licensing\Model\LicenseSource::ENV);
        }

        return $references;
    }

    /**
     * @return list<string>
     */
    private function split(string $raw): array
    {
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $keys = [];
        foreach ($parts as $part) {
            $key = trim($part);
            if ('' !== $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}

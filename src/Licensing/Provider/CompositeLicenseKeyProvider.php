<?php

declare(strict_types=1);

namespace App\Licensing\Provider;

/**
 * Merges all configured license key sources and deduplicates identical keys.
 */
final readonly class CompositeLicenseKeyProvider implements LicenseKeyProviderInterface
{
    public function __construct(
        private EnvLicenseKeyProvider $envProvider,
        private FileLicenseKeyProvider $fileProvider,
        private DatabaseLicenseKeyProvider $databaseProvider,
    ) {
    }

    #[\Override]
    public function getLicenseKeys(): array
    {
        $references = [];
        $seen = [];
        foreach ([
            $this->envProvider->getLicenseKeys(),
            $this->fileProvider->getLicenseKeys(),
            $this->databaseProvider->getLicenseKeys(),
        ] as $providerKeys) {
            foreach ($providerKeys as $reference) {
                $hash = hash('sha256', $reference->key);
                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $references[] = $reference;
            }
        }

        return $references;
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Provider;

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

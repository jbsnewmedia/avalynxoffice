<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Provider;

use AvalynxOffice\Licensing\Model\LicenseKeyReference;
use AvalynxOffice\Licensing\Model\LicenseSource;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Provides license keys from an optional file (AVALYNXOFFICE_LICENSE_FILE).
 * Comma and newline are valid separators.
 */
final readonly class FileLicenseKeyProvider implements LicenseKeyProviderInterface
{
    public function __construct(
        private string $filePath,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[\Override]
    public function getLicenseKeys(): array
    {
        $path = trim($this->filePath);
        if ('' === $path) {
            return [];
        }

        if (!is_file($path)) {
            $this->logger->warning(sprintf('The configured license key file "%s" does not exist.', $path));

            return [];
        }

        $content = @file_get_contents($path);
        if (false === $content) {
            $this->logger->warning(sprintf('The configured license key file "%s" could not be read.', $path));

            return [];
        }

        $references = [];
        foreach (new EnvLicenseKeyProvider($content)->getLicenseKeys() as $reference) {
            $references[] = new LicenseKeyReference($reference->key, LicenseSource::FILE);
        }

        return $references;
    }
}

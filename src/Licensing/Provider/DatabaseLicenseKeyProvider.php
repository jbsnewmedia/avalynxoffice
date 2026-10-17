<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Provider;

use AvalynxOffice\Licensing\Entity\StoredLicenseKey;
use AvalynxOffice\Licensing\Model\LicenseKeyReference;
use AvalynxOffice\Licensing\Model\LicenseSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provides license keys managed through the administration interface.
 */
final readonly class DatabaseLicenseKeyProvider implements LicenseKeyProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function getLicenseKeys(): array
    {
        $stored = $this->entityManager->getRepository(StoredLicenseKey::class)->findBy([], ['addedAt' => 'ASC']);

        $references = [];
        foreach ($stored as $licenseKey) {
            $key = $licenseKey->getLicenseKey();
            if ('' !== $key) {
                $references[] = new LicenseKeyReference($key, LicenseSource::DATABASE);
            }
        }

        return $references;
    }
}

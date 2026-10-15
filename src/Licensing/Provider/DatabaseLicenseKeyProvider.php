<?php

declare(strict_types=1);

namespace App\Licensing\Provider;

use App\Licensing\Entity\StoredLicenseKey;
use App\Licensing\Model\LicenseKeyReference;
use App\Licensing\Model\LicenseSource;
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

<?php

declare(strict_types=1);

namespace App\Licensing\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * A license key entered through the administration interface.
 */
#[ORM\Entity]
#[ORM\Table(name: 'license_key')]
class StoredLicenseKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidV7 $id = null;

    #[ORM\Column(name: 'key_hash', type: 'string', length: 64, unique: true)]
    private string $keyHash;

    #[ORM\Column(name: 'added_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $addedAt;

    public function __construct(#[ORM\Column(name: 'license_key', type: 'text')]
        private string $licenseKey)
    {
        $this->id = Uuid::v7();
        $this->keyHash = hash('sha256', $this->licenseKey);
        $this->addedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function getLicenseKey(): string
    {
        return $this->licenseKey;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Persistent licensing state of the installation. Exactly one row (id=1).
 *
 * The row survives container restarts, so the grace period cannot be reset
 * by restarting the application. It is also used as the advisory row lock
 * for seat accounting during user creation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'license_state')]
class LicenseState
{
    public const int SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(name: 'organization_id', type: 'string', length: 64, nullable: true)]
    private ?string $organizationId = null;

    #[ORM\Column(name: 'installation_id', type: 'string', length: 64, nullable: true)]
    private ?string $installationId = null;

    #[ORM\Column(name: 'violation_started_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $violationStartedAt = null;

    #[ORM\Column(name: 'grace_period_ends_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $gracePeriodEndsAt = null;

    #[ORM\Column(name: 'last_known_status', type: 'string', length: 20, nullable: true)]
    private ?string $lastKnownStatus = null;

    #[ORM\Column(name: 'last_checked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getOrganizationId(): ?string
    {
        return $this->organizationId;
    }

    public function setOrganizationId(?string $organizationId): self
    {
        $this->organizationId = $organizationId;

        return $this;
    }

    public function getInstallationId(): ?string
    {
        return $this->installationId;
    }

    public function setInstallationId(?string $installationId): self
    {
        $this->installationId = $installationId;

        return $this;
    }

    public function getViolationStartedAt(): ?\DateTimeImmutable
    {
        return $this->violationStartedAt;
    }

    public function setViolationStartedAt(?\DateTimeImmutable $violationStartedAt): self
    {
        $this->violationStartedAt = $violationStartedAt;

        return $this;
    }

    public function getGracePeriodEndsAt(): ?\DateTimeImmutable
    {
        return $this->gracePeriodEndsAt;
    }

    public function setGracePeriodEndsAt(?\DateTimeImmutable $gracePeriodEndsAt): self
    {
        $this->gracePeriodEndsAt = $gracePeriodEndsAt;

        return $this;
    }

    public function getLastKnownStatus(): ?string
    {
        return $this->lastKnownStatus;
    }

    public function setLastKnownStatus(?string $lastKnownStatus): self
    {
        $this->lastKnownStatus = $lastKnownStatus;

        return $this;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function setLastCheckedAt(?\DateTimeImmutable $lastCheckedAt): self
    {
        $this->lastCheckedAt = $lastCheckedAt;

        return $this;
    }
}

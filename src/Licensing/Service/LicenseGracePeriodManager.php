<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Service;

use AvalynxOffice\Licensing\Entity\LicenseState;
use AvalynxOffice\Licensing\Model\LicenseStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Loads and persists the licensing state row. The row is created on first
 * access and then stable: organization and installation identity never
 * change on restarts.
 */
final readonly class LicenseGracePeriodManager
{
    /**
     * Minimum seconds between two "last checked" writes to avoid write
     * amplification on read-heavy page loads.
     */
    private const int CHECK_INTERVAL_SECONDS = 60;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Loads the state row, creating and initializing it on first access.
     *
     * @param string|null $configuredOrganizationId from AVALYNXOFFICE_ORGANIZATION_ID
     */
    public function getState(?string $configuredOrganizationId): LicenseState
    {
        $state = $this->entityManager->find(LicenseState::class, LicenseState::SINGLETON_ID);

        if (!$state instanceof LicenseState) {
            $state = new LicenseState();
            $this->entityManager->persist($state);
        }

        if (null !== $configuredOrganizationId && '' !== trim($configuredOrganizationId)) {
            $state->setOrganizationId(trim($configuredOrganizationId));
        } elseif (null === $state->getOrganizationId()) {
            $state->setOrganizationId((string) Uuid::v7());
        }

        if (null === $state->getInstallationId()) {
            $state->setInstallationId((string) Uuid::v7());
        }

        $this->entityManager->flush();

        return $state;
    }

    /**
     * Locks the state row for the current transaction (pessimistic write).
     * Must be called inside an active transaction; the lock serializes
     * concurrent user activations until the transaction commits.
     */
    public function lockStateForUpdate(): LicenseState
    {
        $state = $this->entityManager->find(
            LicenseState::class,
            LicenseState::SINGLETON_ID,
            \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE,
        );

        if (!$state instanceof LicenseState) {
            throw new \LogicException('The license state row must be created before seat accounting can lock it.');
        }

        return $state;
    }

    /**
     * Persists a resolution if the state changed or the last check is stale.
     *
     * @param LicenseStatus|null $status null keeps the previous status untouched
     */
    public function persistResolution(
        LicenseState $state,
        ?LicenseStatus $status,
        ?\DateTimeImmutable $violationStartedAt,
        ?\DateTimeImmutable $gracePeriodEndsAt,
        \DateTimeImmutable $now,
    ): void {
        $changed = $status instanceof LicenseStatus && $status->value !== $state->getLastKnownStatus()
            || $violationStartedAt?->getTimestamp() !== $state->getViolationStartedAt()?->getTimestamp()
            || $gracePeriodEndsAt?->getTimestamp() !== $state->getGracePeriodEndsAt()?->getTimestamp();

        $lastChecked = $state->getLastCheckedAt();
        $checkStale = null === $lastChecked
            || ($now->getTimestamp() - $lastChecked->getTimestamp()) >= self::CHECK_INTERVAL_SECONDS;

        if ($status instanceof LicenseStatus) {
            $state->setLastKnownStatus($status->value);
        }

        $state->setViolationStartedAt($violationStartedAt);
        $state->setGracePeriodEndsAt($gracePeriodEndsAt);

        if ($changed || $checkStale) {
            $state->setLastCheckedAt($now);
        }

        $this->entityManager->flush();
    }
}

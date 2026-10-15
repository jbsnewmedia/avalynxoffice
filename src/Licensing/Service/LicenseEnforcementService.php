<?php

declare(strict_types=1);

namespace App\Licensing\Service;

use App\Licensing\Entity\LicenseState;
use App\Licensing\Exception\LicenseReadOnlyException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Central enforcement point for user-initiated write operations.
 *
 * Technical system writes (mail import, folder synchronization, licensing
 * state, security logging) must NOT call this service.
 */
final readonly class LicenseEnforcementService implements LicenseEnforcementInterface
{
    public function __construct(
        private LicenseManager $licenseManager,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function assertCanPerform(LicenseOperation $operation): void
    {
        if ($this->licenseManager->isReadOnly()) {
            throw new LicenseReadOnlyException($operation);
        }
    }

    /**
     * Guards the creation or activation of an additional user account.
     *
     * When called inside an active transaction, the license state row is
     * locked pessimistically so that concurrent activations cannot slip
     * past the quota. Callers that insert the user account MUST run the
     * check and the insert within the same transaction:
     *
     *     $em->wrapInTransaction(function () use ($em, $enforcement, $user): void {
     *         $enforcement->assertUserSeatAvailable();
     *         $em->persist($user);
     *     });
     *
     * Exceeding the quota is not hard-blocked here (the grace period
     * applies), but the read-only mode blocks any account activation.
     *
     * @throws LicenseReadOnlyException in read-only mode
     */
    #[\Override]
    public function assertUserSeatAvailable(): void
    {
        $this->assertCanPerform(LicenseOperation::USER_CREATE);

        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            return;
        }

        $this->entityManager->find(
            LicenseState::class,
            LicenseState::SINGLETON_ID,
            LockMode::PESSIMISTIC_WRITE,
        );
    }

    #[\Override]
    public function isReadOnly(): bool
    {
        return $this->licenseManager->isReadOnly();
    }
}

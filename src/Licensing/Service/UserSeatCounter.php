<?php

declare(strict_types=1);

namespace App\Licensing\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Counts the Active Seats that consume license seats (APL 1.0 § 1).
 *
 * An Active Seat is an activated personal user account that performed at
 * least one successful authentication (web login or device sync) within the
 * last 30 calendar days. Deactivated accounts and accounts without recent
 * authentication are not counted. The count is executed inside the seat
 * accounting transaction so that concurrent changes are serialized by the
 * license state row lock.
 */
final readonly class UserSeatCounter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
    ) {
    }

    public function countUsedSeats(\DateTimeImmutable $now): int
    {
        return $this->userRepository->countActiveSeats($now);
    }

    /**
     * @return list<User>
     */
    public function getSeatUsers(): array
    {
        // All accounts are listed (including deactivated ones) so the
        // administration interface can reactivate them.
        return $this->entityManager->getRepository(User::class)->findBy([], ['email' => 'ASC']);
    }
}

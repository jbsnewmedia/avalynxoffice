<?php

declare(strict_types=1);

namespace App\Licensing\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Counts the user accounts that consume license seats.
 *
 * Only activated personal accounts are counted. Deactivated accounts are
 * not counted. The count is executed inside the seat accounting
 * transaction so that concurrent activations are serialized by the
 * license state row lock.
 */
final readonly class UserSeatCounter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
    ) {
    }

    public function countUsedSeats(): int
    {
        return $this->userRepository->countActiveUsers();
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

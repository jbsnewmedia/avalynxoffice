<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Licensing;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Licensing\Service\UserSeatCounter;
use AvalynxOffice\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class UserSeatCounterTest extends TestCase
{
    public function testGetSeatUsersListsEveryUserSortedByEmail(): void
    {
        $first = (new User())->setEmail('b@example.com');
        $second = (new User())->setEmail('a@example.com');

        $repository = $this->createStub(UserRepository::class);
        $repository->method('findBy')->willReturn([$second, $first]);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $counter = new UserSeatCounter($entityManager, $repository);

        self::assertSame([$second, $first], $counter->getSeatUsers());
    }

    public function testCountUsedSeatsDelegatesToTheRepository(): void
    {
        $now = new \DateTimeImmutable('2026-10-29 10:00:00');

        $repository = $this->createStub(UserRepository::class);
        $repository->method('countActiveSeats')->willReturn(7);

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $counter = new UserSeatCounter($entityManager, $repository);

        self::assertSame(7, $counter->countUsedSeats($now));
    }
}

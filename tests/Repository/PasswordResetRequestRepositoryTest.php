<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Repository;

use AvalynxOffice\Entity\PasswordResetRequest;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\PasswordResetRequestRepository;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('integration')]
final class PasswordResetRequestRepositoryTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $entityManager;

    private PasswordResetRequestRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->entityManager);
        $this->repository = $this->entityManager->getRepository(PasswordResetRequest::class);
        \assert($this->repository instanceof PasswordResetRequestRepository);
    }

    private function createUser(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('secret');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createRequest(User $user, string $token, string $createdAt): PasswordResetRequest
    {
        $request = new PasswordResetRequest();
        $request->setUser($user);
        $request->setTokenHash(hash('sha256', $token));
        $request->setReleaseNumberHash(hash('sha256', $token.'-number'));
        $request->setStatus(PasswordResetRequest::STATUS_PENDING);
        $request->setExpiresAt(new \DateTimeImmutable('+1 hour'));
        $request->setCreatedAt(new \DateTimeImmutable($createdAt));
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        return $request;
    }

    public function testFindNewestForUserReturnsTheLatestRequest(): void
    {
        $user = $this->createUser('newest@example.com');

        $older = $this->createRequest($user, 'older-token', '-2 hours');
        $newer = $this->createRequest($user, 'newer-token', '-1 hours');

        $found = $this->repository->findNewestForUser($user);

        self::assertNotNull($found);
        self::assertSame($newer->getId(), $found->getId());
        self::assertNotSame($older->getId(), $found->getId());
    }

    public function testFindNewestForUserReturnsNullForUsersWithoutRequests(): void
    {
        $user = $this->createUser('empty@example.com');

        self::assertNull($this->repository->findNewestForUser($user));
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Entity\PasswordResetRequest;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\PasswordResetRequestRepository;
use AvalynxOffice\Repository\UserRepository;
use AvalynxOffice\Service\AdminPasswordResetService;
use Doctrine\ORM\Query;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class AdminPasswordResetServiceTest extends TestCase
{
    #[Group('unit')]
    public function testStartRequestCreatesPendingRequestAndInvalidatesOpenOnes(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');

        $openRequest = new PasswordResetRequest();
        $openRequest->setUser($user);
        $openRequest->setStatus(PasswordResetRequest::STATUS_CLAIMED);

        $resetRepository = $this->createStub(PasswordResetRequestRepository::class);
        $resetRepository->method('findActiveByUser')->willReturn([$openRequest]);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(PasswordResetRequest::class));
        $entityManager->expects($this->atLeastOnce())->method('flush');

        $service = $this->createService($resetRepository, $userRepository, $entityManager);
        $result = $service->startRequest(
            'user@example.com',
            Uuid::v7()->toRfc4122(),
            '127.0.0.1',
        );

        self::assertSame(PasswordResetRequest::STATUS_INVALIDATED, $openRequest->getStatus());
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $result['token']);
        self::assertGreaterThanOrEqual(1000, $result['number']);
        self::assertLessThanOrEqual(9999, $result['number']);
        self::assertSame(PasswordResetRequest::STATUS_PENDING, $result['request']->getStatus());
        self::assertSame($user, $result['request']->getUser());
        self::assertSame(hash('sha256', $result['token']), $result['request']->getTokenHash());
        self::assertSame(
            hash('sha256', 'password-reset-release:'.$result['number']),
            $result['request']->getReleaseNumberHash(),
        );
        self::assertNotNull($result['request']->getExpiresAt());
        self::assertGreaterThan(new \DateTimeImmutable(), $result['request']->getExpiresAt());
    }

    #[Group('unit')]
    public function testStartUserRequestCreatesClaimedRequestAndInvalidatesOpenOnes(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');

        $openRequest = new PasswordResetRequest();
        $openRequest->setUser($user);
        $openRequest->setStatus(PasswordResetRequest::STATUS_PENDING);

        $persisted = [];
        $resetRepository = $this->createStub(PasswordResetRequestRepository::class);
        $resetRepository->method('findActiveByUser')->willReturn([$openRequest]);
        $resetRepository->method('findByToken')->willReturnCallback(
            static function (string $token) use (&$persisted): ?PasswordResetRequest {
                foreach ($persisted as $candidate) {
                    \assert($candidate instanceof PasswordResetRequest);
                    if ($candidate->getTokenHash() === hash('sha256', $token)) {
                        return $candidate;
                    }
                }

                return null;
            },
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (PasswordResetRequest $request) use (&$persisted): void {
                $persisted[] = $request;
            },
        );

        $service = $this->createService($resetRepository, null, $entityManager);
        $result = $service->startUserRequest($user, '127.0.0.1');

        self::assertSame(PasswordResetRequest::STATUS_INVALIDATED, $openRequest->getStatus());
        self::assertSame(PasswordResetRequest::STATUS_CLAIMED, $result['request']->getStatus());
        self::assertSame(hash('sha256', $result['token']), $result['request']->getTokenHash());
        self::assertSame(
            hash('sha256', 'password-reset-release:'.$result['number']),
            $result['request']->getReleaseNumberHash(),
        );
        self::assertNull($result['request']->getCreatedByAdmin());
        self::assertSame('127.0.0.1', $result['request']->getCreatedIp());
    }

    #[Group('unit')]
    public function testAnonymousStartUserRequestKeepsYoungOpenRequestAndReturnsDecoy(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');

        $openRequest = new PasswordResetRequest();
        $openRequest->setUser($user);
        $openRequest->setStatus(PasswordResetRequest::STATUS_CLAIMED);
        $openRequest->setExpiresAt(new \DateTimeImmutable('+10 minutes'));
        $openRequest->setCreatedAt(new \DateTimeImmutable('-1 minute'));

        $resetRepository = $this->createStub(PasswordResetRequestRepository::class);
        $resetRepository->method('findActiveByUser')->willReturn([$openRequest]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = $this->createService($resetRepository, null, $entityManager);
        $result = $service->startUserRequest($user, '127.0.0.1', anonymous: true);

        self::assertSame(PasswordResetRequest::STATUS_CLAIMED, $openRequest->getStatus());
        self::assertNull($result['request']);
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $result['token']);
        self::assertGreaterThanOrEqual(1000, $result['number']);
        self::assertLessThanOrEqual(9999, $result['number']);
    }

    #[Group('unit')]
    public function testAnonymousStartUserRequestReplacesStaleOpenRequest(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');

        $openRequest = new PasswordResetRequest();
        $openRequest->setUser($user);
        $openRequest->setStatus(PasswordResetRequest::STATUS_CLAIMED);
        $openRequest->setExpiresAt(new \DateTimeImmutable('+10 minutes'));
        $openRequest->setCreatedAt(new \DateTimeImmutable(sprintf('-%d seconds', AdminPasswordResetService::ANONYMOUS_REPLACE_AFTER_SECONDS + 1)));

        $persisted = [];
        $resetRepository = $this->createStub(PasswordResetRequestRepository::class);
        $resetRepository->method('findActiveByUser')->willReturn([$openRequest]);
        $resetRepository->method('findByToken')->willReturnCallback(
            static function (string $token) use (&$persisted): ?PasswordResetRequest {
                foreach ($persisted as $candidate) {
                    \assert($candidate instanceof PasswordResetRequest);
                    if ($candidate->getTokenHash() === hash('sha256', $token)) {
                        return $candidate;
                    }
                }

                return null;
            },
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (PasswordResetRequest $request) use (&$persisted): void {
                $persisted[] = $request;
            },
        );

        $service = $this->createService($resetRepository, null, $entityManager);
        $result = $service->startUserRequest($user, '127.0.0.1', anonymous: true);

        self::assertSame(PasswordResetRequest::STATUS_INVALIDATED, $openRequest->getStatus());
        self::assertNotNull($result['request']);
        self::assertSame(PasswordResetRequest::STATUS_CLAIMED, $result['request']->getStatus());
    }

    #[Group('unit')]
    public function testGenerateDecoyCodeLooksLikeARealCodeWithoutRequest(): void
    {
        $service = $this->createService();

        $decoy = $service->generateDecoyCode();

        self::assertNull($decoy['request']);
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $decoy['token']);
        self::assertGreaterThanOrEqual(1000, $decoy['number']);
        self::assertLessThanOrEqual(9999, $decoy['number']);
    }

    #[Group('unit')]
    public function testCompleteRevokesLoginSessionsAndInvalidatesOtherOpenRequests(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');

        $request = new PasswordResetRequest();
        $request->setUser($user);
        $request->setStatus(PasswordResetRequest::STATUS_RELEASED);
        $request->setReleaseExpiresAt(new \DateTimeImmutable('+2 minutes'));

        $other = new PasswordResetRequest();
        $other->setUser($user);
        $other->setStatus(PasswordResetRequest::STATUS_PENDING);

        $resetRepository = $this->createStub(PasswordResetRequestRepository::class);
        $resetRepository->method('findActiveByUser')->willReturn([$request, $other]);

        // LoginSessionService is final: drive a real instance through mocked
        // collaborators and verify that every session row and remember-me
        // token of the user is revoked.
        $sessionRepository = $this->createMock(\AvalynxOffice\Repository\UserLoginSessionRepository::class);
        $sessionRepository->expects(self::once())->method('revokeAll')->with($user)->willReturn(2);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects(self::once())->method('executeStatement')
            ->with(self::stringContains('DELETE FROM rememberme_token'), ['email' => 'user@example.com'])
            ->willReturn(1);

        $appPasswordRepository = $this->createMock(\AvalynxOffice\Repository\UserAppPasswordRepository::class);
        $appPasswordRepository->expects(self::once())->method('revokeAll')->with($user)->willReturn(1);

        $sessionEntityManager = $this->createStub(EntityManagerInterface::class);
        $sessionEntityManager->method('getRepository')->willReturnCallback(
            static fn (string $class): object => \AvalynxOffice\Entity\UserAppPassword::class === $class
                ? $appPasswordRepository
                : $sessionRepository,
        );
        $sessionEntityManager->method('getConnection')->willReturn($connection);

        $loginSessions = new \AvalynxOffice\Service\Login\LoginSessionService(
            $sessionEntityManager,
            new \AvalynxOffice\Service\Login\GeoIpLookupService(null, false),
        );

        $service = new AdminPasswordResetService(
            $resetRepository,
            $this->createStub(UserRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $loginSessions,
        );

        $service->complete($request, 'new-secret');

        self::assertSame(PasswordResetRequest::STATUS_COMPLETED, $request->getStatus());
        self::assertSame(PasswordResetRequest::STATUS_INVALIDATED, $other->getStatus());
    }

    #[Group('unit')]
    public function testStartRequestRejectsUnknownEmail(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn(null);

        $service = $this->createService($this->createStub(PasswordResetRequestRepository::class), $userRepository);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('No active user with this email address.');
        $service->startRequest('unknown@example.com', Uuid::v7()->toRfc4122(), '127.0.0.1');
    }

    #[Group('unit')]
    public function testStartRequestRejectsInactiveUser(): void
    {
        $user = new User();
        $user->setActive(false);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn($user);

        $service = $this->createService($this->createStub(PasswordResetRequestRepository::class), $userRepository);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('No active user with this email address.');
        $service->startRequest('user@example.com', Uuid::v7()->toRfc4122(), '127.0.0.1');
    }

    #[Group('unit')]
    public function testClaimMovesPendingRequestToClaimed(): void
    {
        $request = $this->createRequest(PasswordResetRequest::STATUS_PENDING);
        $request->setExpiresAt(new \DateTimeImmutable('+5 minutes'));

        $resetRepository = $this->createStub(PasswordResetRequestRepository::class);
        $resetRepository->method('findByToken')->willReturn($request);

        $service = $this->createService($resetRepository);

        $claimed = $service->claim('token');

        self::assertSame($request, $claimed);
        self::assertSame(PasswordResetRequest::STATUS_CLAIMED, $claimed->getStatus());
    }

    #[Group('unit')]
    public function testClaimRejectsUnknownToken(): void
    {
        $resetRepository = $this->createStub(PasswordResetRequestRepository::class);
        $resetRepository->method('findByToken')->willReturn(null);

        $service = $this->createService($resetRepository);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Unknown password reset token.');
        $service->claim('token');
    }

    #[Group('unit')]
    public function testClaimRejectsRequestThatIsNotPending(): void
    {
        $request = $this->createRequest(PasswordResetRequest::STATUS_RELEASED);

        $resetRepository = $this->createStub(PasswordResetRequestRepository::class);
        $resetRepository->method('findByToken')->willReturn($request);

        $service = $this->createService($resetRepository);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('This reset request is not waiting to be claimed.');
        $service->claim('token');
    }

    #[Group('unit')]
    public function testClaimMarksExpiredRequestAsExpired(): void
    {
        $request = $this->createRequest(PasswordResetRequest::STATUS_PENDING);
        $request->setExpiresAt(new \DateTimeImmutable('-1 minute'));

        $resetRepository = $this->createStub(PasswordResetRequestRepository::class);
        $resetRepository->method('findByToken')->willReturn($request);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $service = $this->createService($resetRepository, null, $entityManager);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('This reset request has expired.');
        $service->claim('token');

        self::assertSame(PasswordResetRequest::STATUS_EXPIRED, $request->getStatus());
    }

    #[Group('unit')]
    public function testReleaseRequiresClaimedRequest(): void
    {
        $request = $this->createRequest(PasswordResetRequest::STATUS_PENDING);
        $request->setExpiresAt(new \DateTimeImmutable('+5 minutes'));

        $service = $this->createService();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('This reset request is not waiting for a release.');
        $service->release($request, 1234, Uuid::v7()->toRfc4122(), '127.0.0.1');
    }

    #[Group('unit')]
    public function testReleaseWithCorrectNumberReleasesRequest(): void
    {
        $request = $this->createRequest(PasswordResetRequest::STATUS_CLAIMED);
        $request->setExpiresAt(new \DateTimeImmutable('+5 minutes'));
        $request->setReleaseNumberHash(hash('sha256', 'password-reset-release:4321'));

        $adminId = Uuid::v7()->toRfc4122();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $service = $this->createService(null, null, $entityManager);
        $service->release($request, 4321, $adminId, '10.0.0.1');

        self::assertSame(PasswordResetRequest::STATUS_RELEASED, $request->getStatus());
        self::assertNotNull($request->getReleaseExpiresAt());
        self::assertGreaterThan(new \DateTimeImmutable(), $request->getReleaseExpiresAt());
        self::assertSame(0, $request->getAttempts());
    }

    #[Group('unit')]
    public function testReleaseWithWrongNumberIncrementsAttempts(): void
    {
        $request = $this->createRequest(PasswordResetRequest::STATUS_CLAIMED);
        $request->setExpiresAt(new \DateTimeImmutable('+5 minutes'));
        $request->setReleaseNumberHash(hash('sha256', 'password-reset-release:4321'));

        $service = $this->createService();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Incorrect release number.');
        $service->release($request, 1234, Uuid::v7()->toRfc4122(), '127.0.0.1');

        self::assertSame(1, $request->getAttempts());
        self::assertSame(PasswordResetRequest::STATUS_CLAIMED, $request->getStatus());
        self::assertNotNull($request->getReleaseExpiresAt());
    }

    #[Group('unit')]
    public function testReleaseInvalidatesRequestAfterMaxAttempts(): void
    {
        $request = $this->createRequest(PasswordResetRequest::STATUS_CLAIMED);
        $request->setExpiresAt(new \DateTimeImmutable('+5 minutes'));
        $request->setReleaseNumberHash(hash('sha256', 'password-reset-release:4321'));
        $request->incrementAttempts();
        $request->incrementAttempts();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $service = $this->createService(null, null, $entityManager);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Incorrect release number.');
        $service->release($request, 1234, Uuid::v7()->toRfc4122(), '127.0.0.1');

        self::assertSame(PasswordResetRequest::STATUS_INVALIDATED, $request->getStatus());
    }

    #[Group('unit')]
    public function testReleaseMarksRequestExpiredWhenReleaseWindowPassed(): void
    {
        $request = $this->createRequest(PasswordResetRequest::STATUS_CLAIMED);
        $request->setExpiresAt(new \DateTimeImmutable('+5 minutes'));
        $request->setReleaseExpiresAt(new \DateTimeImmutable('-1 minute'));
        $request->setReleaseNumberHash(hash('sha256', 'password-reset-release:4321'));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $service = $this->createService(null, null, $entityManager);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('The release window of this reset request has expired.');
        $service->release($request, 4321, Uuid::v7()->toRfc4122(), '127.0.0.1');

        self::assertSame(PasswordResetRequest::STATUS_EXPIRED, $request->getStatus());
    }

    #[Group('unit')]
    public function testReleaseMarksRequestExpiredWhenClaimDeadlinePassed(): void
    {
        $request = $this->createRequest(PasswordResetRequest::STATUS_CLAIMED);
        $request->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $request->setReleaseNumberHash(hash('sha256', 'password-reset-release:4321'));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $service = $this->createService(null, null, $entityManager);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('This reset request has expired.');
        $service->release($request, 4321, Uuid::v7()->toRfc4122(), '127.0.0.1');

        self::assertSame(PasswordResetRequest::STATUS_EXPIRED, $request->getStatus());
    }

    #[Group('unit')]
    public function testCompleteSetsPasswordAndCompletesRequest(): void
    {
        $user = new User();
        $user->setPassword('old-hash');

        $request = $this->createRequest(PasswordResetRequest::STATUS_RELEASED);
        $request->setUser($user);
        $request->setReleaseExpiresAt(new \DateTimeImmutable('+2 minutes'));

        $service = $this->createService();
        $service->complete($request, 'new-secret');

        self::assertSame('hashed:new-secret', $user->getPassword());
        self::assertSame(PasswordResetRequest::STATUS_COMPLETED, $request->getStatus());
        self::assertNotNull($request->getCompletedAt());
    }

    #[Group('unit')]
    public function testCompleteRequiresReleasedRequest(): void
    {
        $request = $this->createRequest(PasswordResetRequest::STATUS_CLAIMED);
        $request->setReleaseExpiresAt(new \DateTimeImmutable('+2 minutes'));

        $service = $this->createService();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('This reset request has not been released.');
        $service->complete($request, 'new-secret');
    }

    #[Group('unit')]
    public function testCompleteRejectsExpiredReleaseWindow(): void
    {
        $request = $this->createRequest(PasswordResetRequest::STATUS_RELEASED);
        $request->setReleaseExpiresAt(new \DateTimeImmutable('-1 minute'));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $service = $this->createService(null, null, $entityManager);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('The release window of this reset request has expired.');
        $service->complete($request, 'new-secret');

        self::assertSame(PasswordResetRequest::STATUS_EXPIRED, $request->getStatus());
    }

    #[Group('unit')]
    public function testCompleteRejectsRequestWithoutUser(): void
    {
        $request = new PasswordResetRequest();
        $request->setStatus(PasswordResetRequest::STATUS_RELEASED);
        $request->setReleaseExpiresAt(new \DateTimeImmutable('+2 minutes'));

        $service = $this->createService();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('The user of this reset request no longer exists.');
        $service->complete($request, 'new-secret');
    }

    #[Group('unit')]
    public function testIsAwaitingUserChecksStatusAndDeadline(): void
    {
        $service = $this->createService();

        $pending = $this->createRequest(PasswordResetRequest::STATUS_PENDING);
        $pending->setExpiresAt(new \DateTimeImmutable('+1 minute'));
        self::assertTrue($service->isAwaitingUser($pending));

        $expired = $this->createRequest(PasswordResetRequest::STATUS_PENDING);
        $expired->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        self::assertFalse($service->isAwaitingUser($expired));

        $claimed = $this->createRequest(PasswordResetRequest::STATUS_CLAIMED);
        $claimed->setExpiresAt(new \DateTimeImmutable('+1 minute'));
        self::assertTrue($service->isAwaitingUser($claimed));

        $released = $this->createRequest(PasswordResetRequest::STATUS_RELEASED);
        $released->setExpiresAt(new \DateTimeImmutable('+1 minute'));
        self::assertTrue($service->isAwaitingUser($released));

        $completed = $this->createRequest(PasswordResetRequest::STATUS_COMPLETED);
        $completed->setExpiresAt(new \DateTimeImmutable('+1 minute'));
        self::assertFalse($service->isAwaitingUser($completed));
    }

    #[Group('unit')]
    public function testFindRequestByIdReturnsTheRequest(): void
    {
        $request = $this->createRequest(PasswordResetRequest::STATUS_PENDING);
        $id = $request->getId()?->toRfc4122() ?? '';

        $query = $this->createStub(Query::class);
        $query->method('getResult')->willReturn([$request]);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $resetRepository = $this->createStub(PasswordResetRequestRepository::class);
        $resetRepository->method('createQueryBuilder')->willReturn($queryBuilder);

        $service = $this->createService($resetRepository);

        self::assertSame($request, $service->findRequestById($id));
    }

    #[Group('unit')]
    public function testFindRequestByIdReturnsNullForUnknownId(): void
    {
        $query = $this->createStub(Query::class);
        $query->method('getResult')->willReturn([]);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $resetRepository = $this->createStub(PasswordResetRequestRepository::class);
        $resetRepository->method('createQueryBuilder')->willReturn($queryBuilder);

        $service = $this->createService($resetRepository);

        self::assertNull($service->findRequestById(Uuid::v7()->toRfc4122()));
    }

    #[Group('unit')]
    public function testListOpenRequestsDelegatesToRepository(): void
    {
        $openRequests = [$this->createRequest(PasswordResetRequest::STATUS_PENDING)];

        $resetRepository = $this->createStub(PasswordResetRequestRepository::class);
        $resetRepository->method('findOpenRequests')->willReturn($openRequests);

        $service = $this->createService($resetRepository);

        self::assertSame($openRequests, $service->listOpenRequests());
    }

    private function createRequest(string $status): PasswordResetRequest
    {
        $request = new PasswordResetRequest();
        $request->setUser(new User());
        $request->setStatus($status);

        return $request;
    }

    private function createService(
        ?PasswordResetRequestRepository $resetRepository = null,
        ?UserRepository $userRepository = null,
        ?EntityManagerInterface $entityManager = null,
    ): AdminPasswordResetService {
        $resetRepository ??= $this->createStub(PasswordResetRequestRepository::class);
        $userRepository ??= $this->createStub(UserRepository::class);
        $entityManager ??= $this->createStub(EntityManagerInterface::class);

        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturnCallback(
            static fn (User $user, string $password): string => 'hashed:'.$password,
        );

        return new AdminPasswordResetService($resetRepository, $userRepository, $entityManager, $passwordHasher);
    }
}

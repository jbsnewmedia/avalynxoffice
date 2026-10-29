<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\EventSubscriber;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Entity\UserLoginSession;
use AvalynxOffice\EventSubscriber\LoginSessionSubscriber;
use AvalynxOffice\Service\Login\GeoIpLookupService;
use AvalynxOffice\Service\Login\LoginSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class LoginSessionSubscriberTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
    }

    private function createSubscriber(): LoginSessionSubscriber
    {
        $loginSessionService = new LoginSessionService(
            $this->entityManager,
            new GeoIpLookupService(null),
        );

        return new LoginSessionSubscriber($loginSessionService, $this->createStub(TokenStorageInterface::class));
    }

    private function createLogoutEvent(?TokenInterface $token, ?string $sessionToken): LogoutEvent
    {
        $request = Request::create('/logout');
        $request->setSession(new Session(new MockArraySessionStorage()));
        if (null !== $sessionToken) {
            $request->getSession()->set(LoginSessionService::SESSION_ATTRIBUTE, $sessionToken);
        }

        return new LogoutEvent($request, $token);
    }

    public function testLogoutRevokesTheActiveSession(): void
    {
        $user = (new User())->setEmail('logout@example.com');
        $session = (new UserLoginSession())->setUser($user);

        $sessionRepository = $this->createStub(\AvalynxOffice\Repository\UserLoginSessionRepository::class);
        $sessionRepository->method('findActiveByToken')->willReturn($session);

        $this->entityManager->method('getRepository')->willReturn($sessionRepository);
        $this->entityManager->method('flush');

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $event = $this->createLogoutEvent($token, 'session-token');

        $this->createSubscriber()->onLogout($event);

        self::assertNotNull($session->getRevokedAt(), 'The active session row must be revoked on logout.');
    }

    public function testLogoutWithoutUserIsIgnored(): void
    {
        $sessionRepository = $this->createStub(\AvalynxOffice\Repository\UserLoginSessionRepository::class);
        $sessionRepository->method('findActiveByToken')->willThrowException(new \RuntimeException('must not be queried'));

        $this->entityManager->method('getRepository')->willReturn($sessionRepository);

        $event = $this->createLogoutEvent(null, 'session-token');

        $this->createSubscriber()->onLogout($event);

        self::expectNotToPerformAssertions();
    }

    public function testLogoutWithoutSessionTokenKeepsTheSessions(): void
    {
        $user = (new User())->setEmail('logout@example.com');

        $sessionRepository = $this->createStub(\AvalynxOffice\Repository\UserLoginSessionRepository::class);
        $sessionRepository->method('findActiveByToken')->willThrowException(new \RuntimeException('must not be queried'));

        $this->entityManager->method('getRepository')->willReturn($sessionRepository);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $event = $this->createLogoutEvent($token, null);

        $this->createSubscriber()->onLogout($event);

        self::expectNotToPerformAssertions();
    }
}

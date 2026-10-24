<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Login;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Service\Login\GeoIpLookupService;
use AvalynxOffice\Service\Login\LoginSessionService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[Group('integration')]
final class LoginSessionServiceTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $entityManager;

    private LoginSessionService $service;

    private User $user;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->entityManager);
        $this->seedUser();
        $this->service = new LoginSessionService(
            $this->entityManager,
            new class extends GeoIpLookupService {
                public function __construct()
                {
                    parent::__construct(null);
                }

                #[\Override]
                protected function fetchGeo(string $ip): ?array
                {
                    return ['status' => 'success', 'city' => 'Mainz', 'country' => 'Germany'];
                }
            },
        );
    }

    private function seedUser(): void
    {
        $this->user = new User()->setEmail('session@example.com')->setPassword('x');
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    /**
     * Builds a request with a started session (like the framework does).
     */
    private function request(string $ip = '203.0.113.10', string $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/121.0 Safari/537.36', string $cookieSessionId = 'default'): Request
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => $userAgent,
        ]);
        $request->setSession(new Session(new MockArraySessionStorage($cookieSessionId)));

        return $request;
    }

    public function testRegisterCreatesRowWithTokenDeviceAndRememberState(): void
    {
        $request = $this->request();
        $request->getSession()->set('login_remember_me', true);

        $this->service->register($this->user, $request);

        $rows = $this->service->activeFor($this->user);
        self::assertCount(1, $rows);

        $row = $rows[0];
        self::assertSame('Windows', $row->getOs());
        self::assertSame('Chrome', $row->getBrowser());
        self::assertSame('203.0.113.10', $row->getIpAddress());
        self::assertSame('Mainz', $row->getGeoCity());
        self::assertSame('Germany', $row->getGeoCountry());
        self::assertTrue($row->isRememberMe());
        self::assertNotNull($row->getLastSeenAt());
        self::assertNull($row->getRevokedAt());

        // The token is stored in the session for later request matching.
        self::assertSame($row->getSessionToken(), $request->getSession()->get(LoginSessionService::SESSION_ATTRIBUTE));
    }

    public function testRegisterWithSameSessionRefreshesInsteadOfDuplicating(): void
    {
        $request = $this->request();
        $this->service->register($this->user, $request);
        $this->service->register($this->user, $request);

        self::assertCount(1, $this->service->activeFor($this->user));
    }

    public function testRegisterOnSecondDeviceCreatesSecondRow(): void
    {
        $first = $this->request(ip: '203.0.113.10', cookieSessionId: 'device-1');
        $this->service->register($this->user, $first);

        $second = $this->request(ip: '198.51.100.5', userAgent: 'Mozilla/5.0 (iPhone) Safari/604.1', cookieSessionId: 'device-2');
        $this->service->register($this->user, $second);

        self::assertCount(2, $this->service->activeFor($this->user));
    }

    public function testHandleRequestTouchesActivity(): void
    {
        $request = $this->request();
        $this->service->register($this->user, $request);
        $row = $this->service->activeFor($this->user)[0];
        $row->setLastSeenAt(new \DateTimeImmutable('-10 minutes'));
        $this->entityManager->flush();

        $this->service->handleRequest($this->user, $request);

        self::assertGreaterThan(
            new \DateTimeImmutable('-9 minutes')->getTimestamp(),
            $row->getLastSeenAt()?->getTimestamp() ?? 0,
        );
    }

    public function testHandleRequestWithoutTokenDoesNothing(): void
    {
        $request = $this->request();
        $this->service->handleRequest($this->user, $request);

        self::assertCount(0, $this->service->activeFor($this->user));
    }

    public function testHandleRequestTerminatesRevokedSession(): void
    {
        $request = $this->request();
        $this->service->register($this->user, $request);
        $token = (string) $request->getSession()->get(LoginSessionService::SESSION_ATTRIBUTE);

        $this->service->revokeByToken($this->user, $token);
        $request->getSession()->set(LoginSessionService::SESSION_ATTRIBUTE, $token);

        $this->service->handleRequest($this->user, $request);

        // The session was invalidated and the force-logout flag is set.
        self::assertFalse($request->getSession()->has(LoginSessionService::SESSION_ATTRIBUTE));
        self::assertTrue($this->service->mustTerminate($request));
    }

    public function testRevokeOnlyAcceptsOwnActiveSessions(): void
    {
        $request = $this->request();
        $this->service->register($this->user, $request);
        $row = $this->service->activeFor($this->user)[0];

        self::assertFalse($this->service->revoke($this->user, '00000000-0000-0000-0000-000000000000'));

        self::assertTrue($this->service->revoke($this->user, (string) $row->getId()));
        self::assertFalse($this->service->revoke($this->user, (string) $row->getId()), 'already revoked');
    }

    public function testRevokeOthersKeepsCurrentAndRevokesRest(): void
    {
        $first = $this->request(ip: '203.0.113.10', cookieSessionId: 'device-1');
        $this->service->register($this->user, $first);
        $currentToken = (string) $first->getSession()->get(LoginSessionService::SESSION_ATTRIBUTE);

        $second = $this->request(ip: '198.51.100.5', cookieSessionId: 'device-2');
        $this->service->register($this->user, $second);
        $third = $this->request(ip: '198.51.100.6', cookieSessionId: 'device-3');
        $this->service->register($this->user, $third);

        $revoked = $this->service->revokeOthers($this->user, $currentToken);

        self::assertSame(2, $revoked);
        self::assertCount(1, $this->service->activeFor($this->user));
    }

    public function testActiveForIsEmptyForUnknownUser(): void
    {
        $stranger = new User()->setEmail('stranger@example.com')->setPassword('x');
        $this->entityManager->persist($stranger);
        $this->entityManager->flush();

        self::assertSame([], $this->service->activeFor($stranger));
    }
}

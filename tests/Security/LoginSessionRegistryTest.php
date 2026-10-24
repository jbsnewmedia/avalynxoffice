<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Security;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\UserLoginSessionRepository;
use AvalynxOffice\Service\Login\LoginSessionService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The concurrent-login registry is filled on real form logins, revoked on
 * logout and a kicked session terminates the browser session together with
 * its remember-me cookie.
 */
#[Group('integration')]
final class LoginSessionRegistryTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $user;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->refreshDatabase($this->entityManager);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User()->setEmail('registry@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'secret'));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    private function login(string $password = 'secret', bool $rememberMe = false): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $parameters = [
            '_username' => 'registry@example.com',
            '_password' => $password,
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'),
        ];

        if ($rememberMe) {
            $parameters['_remember_me'] = 'on';
        }

        $this->client->request('POST', '/login', $parameters, [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/121.0 Safari/537.36',
        ]);
        self::assertResponseRedirects('/mails');
    }

    private function repository(): UserLoginSessionRepository
    {
        $repository = static::getContainer()->get(UserLoginSessionRepository::class);
        \assert($repository instanceof UserLoginSessionRepository);

        return $repository;
    }

    public function testInteractiveLoginRegistersSessionRow(): void
    {
        $this->login(rememberMe: true);

        $rows = $this->repository()->findActiveByUser($this->user);
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]->isRememberMe());
        self::assertStringContainsString('Chrome', (string) $rows[0]->getBrowser());
        self::assertSame('203.0.113.10', $rows[0]->getIpAddress());
    }

    public function testRevokedSessionIsTerminatedWithRememberCookieClear(): void
    {
        $this->login(rememberMe: true);

        // A follow-up request makes sure the (regenerated) session cookie is
        // stored before the session is kicked.
        $this->client->request('GET', '/mails');
        self::assertResponseIsSuccessful();

        $repository = static::getContainer()->get(UserLoginSessionRepository::class);
        \assert($repository instanceof UserLoginSessionRepository);
        $row = $repository->findActiveByUser($this->user)[0];

        // Kick: revoke the row server-side through the same service the
        // profile page's revoke endpoint uses.
        static::getContainer()->get(LoginSessionService::class)->revoke($this->user, (string) $row->getId());

        // The very next request: the session was invalidated (no registry
        // token anymore) and the remember-me cookie was removed.
        $this->client->request('GET', '/mails');

        self::assertNull(
            $this->client->getRequest()->getSession()->get(LoginSessionService::SESSION_ATTRIBUTE),
            'Expected the kicked session to be invalidated',
        );
        // The remember-me cookie was removed (deletion cookie expired).
        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'));

        // And the follow-up request is logged out.
        $this->client->request('GET', '/mails');
        self::assertResponseRedirects('/login');
    }
}

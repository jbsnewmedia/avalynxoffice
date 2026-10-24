<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\UserLoginSessionRepository;
use AvalynxOffice\Service\Login\LoginSessionService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('integration')]
final class ProfileSessionsControllerTest extends WebTestCase
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
        $this->user = new User()->setEmail('sessions-page@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'secret'));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    private function createSession(string $ip, string $userAgent): string
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $parameters = [
            '_username' => 'sessions-page@example.com',
            '_password' => 'secret',
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'),
            '_remember_me' => 'on',
        ];
        $server = ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent];

        $this->client->request('POST', '/login', $parameters, [], $server);
        self::assertResponseRedirects('/mails');

        $token = $this->client->getRequest()->getSession()->get(LoginSessionService::SESSION_ATTRIBUTE);
        \assert(is_string($token) && '' !== $token);

        return $token;
    }

    public function testSessionsPageRendersWithDeviceDetails(): void
    {
        $this->createSession('203.0.113.50', 'Mozilla/5.0 (Windows NT 10.0) Chrome/121.0 Safari/537.36');

        $this->client->request('GET', '/profile/sessions');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Chrome', $content);
        self::assertStringContainsString('Windows', $content);
        self::assertStringContainsString('203.0.113.50', $content);
        self::assertStringContainsString('This session', $content); // test environment renders EN
    }

    public function testRevokeEndsAnotherSession(): void
    {
        $this->createSession('203.0.113.50', 'Mozilla/5.0 Chrome/121.0');

        $user = $this->entityManager->find(User::class, $this->user->getId());
        \assert($user instanceof User);

        $repository = static::getContainer()->get(UserLoginSessionRepository::class);
        \assert($repository instanceof UserLoginSessionRepository);

        // A second login on another device, created directly in the registry.
        $other = new \AvalynxOffice\Entity\UserLoginSession()
            ->setUser($user)
            ->setSessionToken('other-device-token')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $this->client->request('GET', '/profile/sessions');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $repository->findActiveByUser($user), 'Expected the current and the second device session');

        $csrf = $this->client->getCrawler()->filter(sprintf('form[action="/profile/sessions/%s/revoke"] input[name="_token"]', $other->getId()))->attr('value');

        $this->client->request('POST', sprintf('/profile/sessions/%s/revoke', $other->getId()), ['_token' => $csrf]);

        self::assertResponseRedirects('/profile/sessions');

        $repository = static::getContainer()->get(\AvalynxOffice\Repository\UserLoginSessionRepository::class);
        \assert($repository instanceof UserLoginSessionRepository);
        $remaining = $repository->findActiveByUser($user);
        self::assertCount(1, $remaining);
        self::assertNotSame('other-device-token', $remaining[0]->getSessionToken());
    }

    public function testRevokeRejectsInvalidCsrfToken(): void
    {
        $this->createSession('203.0.113.50', 'Mozilla/5.0 Chrome/121.0');

        $repository = static::getContainer()->get(\AvalynxOffice\Repository\UserLoginSessionRepository::class);
        \assert($repository instanceof UserLoginSessionRepository);
        $row = $repository->findActiveByUser($this->user)[0];

        $this->client->request('GET', '/profile/sessions');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', sprintf('/profile/sessions/%s/revoke', $row->getId()), ['_token' => 'invalid']);

        self::assertResponseRedirects('/profile/sessions');
        self::assertCount(1, $repository->findActiveByUser($this->user));
    }

    public function testRevokeOthersKeepsCurrentSession(): void
    {
        $this->createSession('203.0.113.50', 'Mozilla/5.0 Chrome/121.0');

        $user = $this->entityManager->find(User::class, $this->user->getId());
        \assert($user instanceof User);

        $repository = static::getContainer()->get(\AvalynxOffice\Repository\UserLoginSessionRepository::class);
        \assert($repository instanceof UserLoginSessionRepository);
        $current = $repository->findActiveByUser($user)[0];

        // A second "device" session created directly through the service.
        $other = new \AvalynxOffice\Entity\UserLoginSession()
            ->setUser($user)
            ->setSessionToken('other-device-token')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $this->client->request('GET', '/profile/sessions');
        self::assertResponseIsSuccessful();
        $csrf = $this->client->getCrawler()->filter('form[action="/profile/sessions/revoke-others"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/profile/sessions/revoke-others', ['_token' => $csrf]);

        self::assertResponseRedirects('/profile/sessions');
        $remaining = $repository->findActiveByUser($this->user);
        self::assertCount(1, $remaining);
        self::assertSame($current->getId()->toRfc4122(), $remaining[0]->getId()->toRfc4122());
    }
}

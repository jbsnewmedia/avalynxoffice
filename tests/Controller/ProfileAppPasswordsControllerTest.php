<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\UserAppPasswordRepository;
use AvalynxOffice\Service\Login\AppPasswordService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('integration')]
final class ProfileAppPasswordsControllerTest extends WebTestCase
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
        $this->user = new User()->setEmail('app-passwords@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'secret'));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();

        $this->client->loginUser($this->user);
    }

    public function testPageRequiresAuthentication(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/profile/app-passwords');

        self::assertResponseRedirects('/login');
    }

    public function testPageShowsEasConnectionBox(): void
    {
        $crawler = $this->client->request('GET', '/profile/app-passwords');

        self::assertResponseIsSuccessful();

        // EAS_BASE_URL is not set in the test environment: the box renders
        // with the account name and the not-configured hint, without a
        // server URL row.
        self::assertSame(1, $crawler->filter('[data-eas-connection-box]')->count());
        self::assertSame(0, $crawler->filter('[data-eas-connection-box] [data-eas-server-url]')->count());
        self::assertStringContainsString('app-passwords@example.com', (string) $crawler->filter('[data-eas-connection-box]')->text());
    }

    public function testCreateShowsPasswordOnceAndStoresOnlyHash(): void
    {
        $crawler = $this->client->request('GET', '/profile/app-passwords');
        self::assertResponseIsSuccessful();
        $csrf = $crawler->filter('form[action="/profile/app-passwords"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/profile/app-passwords', ['_token' => $csrf, 'label' => 'iPhone']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame('/profile/app-passwords', $data['redirect']);

        $crawler = $this->client->request('GET', '/profile/app-passwords');
        self::assertResponseIsSuccessful();
        $plain = $crawler->filter('#newAppPassword')->attr('value');
        self::assertMatchesRegularExpression('/^[a-z2-9]{5}(-[a-z2-9]{5}){3}$/', (string) $plain);

        $repository = static::getContainer()->get(UserAppPasswordRepository::class);
        \assert($repository instanceof UserAppPasswordRepository);
        $rows = $repository->findActiveByUser($this->user);
        self::assertCount(1, $rows);
        self::assertSame('iPhone', $rows[0]->getLabel());
        self::assertNotSame($plain, $rows[0]->getPasswordHash());
        self::assertTrue(password_verify(AppPasswordService::normalize((string) $plain), $rows[0]->getPasswordHash()));
        self::assertTrue(password_verify(AppPasswordService::normalize(strtoupper(str_replace('-', ' ', (string) $plain))), $rows[0]->getPasswordHash()));

        // Second page load must not show the password again.
        $crawler = $this->client->request('GET', '/profile/app-passwords');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#newAppPassword'));
        self::assertStringNotContainsString((string) $plain, (string) $this->client->getResponse()->getContent());
    }

    public function testCreateRejectsInvalidCsrfTokenAndEmptyLabel(): void
    {
        $this->client->request('POST', '/profile/app-passwords', ['_token' => 'invalid', 'label' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);

        $crawler = $this->client->request('GET', '/profile/app-passwords');
        $csrf = $crawler->filter('form[action="/profile/app-passwords"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/profile/app-passwords', ['_token' => $csrf, 'label' => '   ']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('label', $data['invalid']);

        $repository = static::getContainer()->get(UserAppPasswordRepository::class);
        self::assertCount(0, $repository->findActiveByUser($this->user));
    }

    public function testRevokeOnlyAffectsOwnPasswords(): void
    {
        $service = static::getContainer()->get(AppPasswordService::class);
        \assert($service instanceof AppPasswordService);
        [$own] = $service->create($this->user, 'Own');

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = new User()->setEmail('other-app-passwords@example.com');
        $other->setPassword($hasher->hashPassword($other, 'secret'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        [$foreign] = $service->create($other, 'Foreign');

        $crawler = $this->client->request('GET', '/profile/app-passwords');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Foreign', (string) $this->client->getResponse()->getContent());

        // Foreign password: the service refuses to revoke rows of other users
        // (the controller only ever passes the current user).
        self::assertFalse($service->revoke($this->user, (string) $foreign->getId()));

        // ... and the HTTP route for a foreign id does not revoke anything either.
        $this->client->request('POST', sprintf('/profile/app-passwords/%s/revoke', $foreign->getId()), ['_token' => 'invalid']);
        self::assertResponseRedirects('/profile/app-passwords');

        $repository = static::getContainer()->get(UserAppPasswordRepository::class);
        \assert($repository instanceof UserAppPasswordRepository);
        self::assertCount(1, $repository->findActiveByUser($other));

        $csrf = $crawler->filter(sprintf('form[action="/profile/app-passwords/%s/revoke"] input[name="_token"]', $own->getId()))->attr('value');
        $this->client->request('POST', sprintf('/profile/app-passwords/%s/revoke', $own->getId()), ['_token' => $csrf]);
        self::assertResponseRedirects('/profile/app-passwords');
        self::assertCount(0, $repository->findActiveByUser($this->user));
    }

    public function testCreateAnswersWithJsonFailureWhenTheLimitIsReached(): void
    {
        $service = static::getContainer()->get(AppPasswordService::class);
        \assert($service instanceof AppPasswordService);
        for ($index = 0; $index < AppPasswordService::MAX_ACTIVE; $index++) {
            $service->create($this->user, 'Password '.$index);
        }

        // The page shows the limit hint instead of the form, so the token is
        // seeded into the session directly (same trick as setCsrfToken()).
        $this->client->request('GET', '/profile/app-passwords');
        $session = $this->client->getRequest()->getSession();
        $csrf = 'test-csrf-token-'.bin2hex(random_bytes(8));
        $session->set('_csrf/app_password_create', $csrf);
        $session->save();

        $this->client->request('POST', '/profile/app-passwords', ['_token' => $csrf, 'label' => 'One more']);
        self::assertResponseStatusCodeSame(403);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
        self::assertArrayHasKey('message', $payload);
        self::assertArrayNotHasKey('invalid', $payload);
        self::assertCount(AppPasswordService::MAX_ACTIVE, $service->activeFor($this->user));
    }
}

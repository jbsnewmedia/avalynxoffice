<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Security;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The "stay logged in" checkbox on the login page keeps its state after a
 * failed login: it renders exactly as the user left it in the last attempt.
 */
#[Group('integration')]
final class LoginRememberMeTest extends WebTestCase
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
        $this->seedUser();
    }

    private function seedUser(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('login@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'secret'));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    /**
     * Attempts a login like a browser: loads the login page (starts the
     * session, provides the CSRF token) and submits the form.
     */
    private function attemptLogin(string $password, bool $rememberMe): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $parameters = [
            '_username' => 'login@example.com',
            '_password' => $password,
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'),
        ];

        if ($rememberMe) {
            $parameters['_remember_me'] = 'on';
        }

        $this->client->request('POST', '/login', $parameters);
    }

    /**
     * Renders the login page and returns the state of the remember-me
     * checkbox: "checked" when set, null when unchecked.
     */
    private function openLoginPage(): ?string
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter('#remember'));

        return $crawler->filter('#remember')->attr('checked');
    }

    public function testFailedLoginKeepsRememberMeChecked(): void
    {
        $this->attemptLogin('wrong', true);
        self::assertResponseRedirects('/login');

        self::assertSame('checked', $this->openLoginPage());
    }

    public function testFailedLoginLeavesRememberMeUnchecked(): void
    {
        $this->attemptLogin('wrong', false);
        self::assertResponseRedirects('/login');

        self::assertNull($this->openLoginPage());
    }

    public function testSuccessfulLoginRemembersCheckedState(): void
    {
        $this->attemptLogin('secret', true);
        self::assertResponseRedirects('/mails');

        $flag = $this->client->getRequest()->getSession()->get('login_remember_me');
        self::assertTrue($flag);
    }

    public function testSuccessfulLoginRemembersUncheckedState(): void
    {
        $this->attemptLogin('secret', false);
        self::assertResponseRedirects('/mails');

        $flag = $this->client->getRequest()->getSession()->get('login_remember_me');
        self::assertFalse($flag);
    }
}

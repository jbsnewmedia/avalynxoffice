<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\PasswordResetRequest;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\PasswordResetRequestRepository;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('integration')]
final class AdminPasswordResetFlowTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private const TARGET_EMAIL = 'target@example.com';
    private const TARGET_PASSWORD = 'old-password-123';
    private const NEW_PASSWORD = 'new-password-456';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private PasswordResetRequestRepository $resetRepository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
        $resetRepository = static::getContainer()->get(PasswordResetRequestRepository::class);
        \assert($resetRepository instanceof PasswordResetRequestRepository);
        $this->resetRepository = $resetRepository;

        $this->refreshDatabase($this->entityManager);
        $this->seedData();
    }

    public function testAdminPageRedirectsAnonymousUsers(): void
    {
        $this->client->request('GET', '/admin/password-resets');
        self::assertResponseRedirects('/login');
    }

    public function testAdminPageIsDeniedForRegularUsers(): void
    {
        $this->loginUser(self::TARGET_EMAIL);

        $this->client->request('GET', '/admin/password-resets');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminPageRendersForAdministrators(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/password-resets');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Password resets');
        self::assertSame('Start reset', trim((string) $crawler->filter('form button[type=submit]')->text()));
    }

    public function testUserStartsCodeFlowAnonymouslyAndCompletes(): void
    {
        $crawler = $this->client->request('GET', '/profile/password-reset');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#reset-code');

        $codeInputs = $crawler->filter('#reset-code input[name="_token"]');
        \assert($codeInputs->count() > 0);

        $this->client->request('POST', '/profile/password-reset/code', [
            'email' => self::TARGET_EMAIL,
            '_token' => (string) $codeInputs->attr('value'),
        ]);
        self::assertResponseIsSuccessful();

        $payload = $this->jsonPayload();
        self::assertTrue($payload['success']);
        self::assertSame('/profile/password-reset', $payload['redirect']);

        // The client follows the redirect: the GET page renders the freshly
        // created code exactly once from the session.
        [$token, $number] = $this->loadCodePageAndReadCode();

        // The user generated the code themselves: the request starts claimed.
        $openRequest = $this->findResetRequest($token);
        self::assertSame(PasswordResetRequest::STATUS_CLAIMED, $openRequest->getStatus());
        self::assertNull($openRequest->getCreatedByAdmin());

        // The administrator can release right away - no flow page visit needed.
        $this->loginAdmin();
        $this->release($openRequest, $number);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'The release has been granted.');

        // The user sets the new password on the flow page.
        $this->submitPassword($token, self::NEW_PASSWORD, self::NEW_PASSWORD);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonPayload()['success']);

        $target = $this->findUser(self::TARGET_EMAIL);
        \assert(null !== $target);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        self::assertTrue($hasher->isPasswordValid($target, self::NEW_PASSWORD));
    }

    public function testUserStartWithoutEmailAnswersInvalidField(): void
    {
        $crawler = $this->client->request('GET', '/profile/password-reset');
        $codeInputs = $crawler->filter('#reset-code input[name="_token"]');
        \assert($codeInputs->count() > 0);

        $this->client->request('POST', '/profile/password-reset/code', [
            'email' => '',
            '_token' => (string) $codeInputs->attr('value'),
        ]);
        self::assertResponseStatusCodeSame(422);

        $payload = $this->jsonPayload();
        self::assertFalse($payload['success']);
        self::assertSame('Please enter your email address.', $payload['invalid']['email']);
    }

    public function testUserStartWithInvalidEmailAnswersInvalidField(): void
    {
        $crawler = $this->client->request('GET', '/profile/password-reset');
        $codeInputs = $crawler->filter('#reset-code input[name="_token"]');
        \assert($codeInputs->count() > 0);

        $this->client->request('POST', '/profile/password-reset/code', [
            'email' => 'not-an-email',
            '_token' => (string) $codeInputs->attr('value'),
        ]);
        self::assertResponseStatusCodeSame(422);

        $payload = $this->jsonPayload();
        self::assertFalse($payload['success']);
        self::assertSame('Please enter a valid email address.', $payload['invalid']['email']);
    }

    public function testSubmitPasswordRejectsTooShortPassword(): void
    {
        ['token' => $token, 'number' => $number] = $this->startReset();

        // Visiting the flow page claims the request - only a claimed request
        // can be released by the administrator.
        $this->openFlowPage($token);

        $openRequest = $this->findResetRequest($token);
        $this->release($openRequest, $number);
        $this->client->followRedirect();

        $this->submitPassword($token, 'short', 'short');
        self::assertResponseStatusCodeSame(422);

        $payload = $this->jsonPayload();
        self::assertFalse($payload['success']);
        self::assertSame('The password must be at least 8 characters long.', $payload['invalid']['password']);

        // The rejected password must never become valid; the flow must still
        // accept a proper password afterwards.
        $this->submitPassword($token, self::NEW_PASSWORD, self::NEW_PASSWORD);
        self::assertResponseIsSuccessful();

        $target = $this->findUser(self::TARGET_EMAIL);
        \assert(null !== $target);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        self::assertFalse($hasher->isPasswordValid($target, 'short'));
        self::assertTrue($hasher->isPasswordValid($target, self::NEW_PASSWORD));
    }

    public function testUserStartWithUnknownEmailAnswersInvalidField(): void
    {
        $crawler = $this->client->request('GET', '/profile/password-reset');
        $codeInputs = $crawler->filter('#reset-code input[name="_token"]');
        \assert($codeInputs->count() > 0);

        $this->client->request('POST', '/profile/password-reset/code', [
            'email' => 'nobody@example.com',
            '_token' => (string) $codeInputs->attr('value'),
        ]);
        self::assertResponseStatusCodeSame(422);

        $payload = $this->jsonPayload();
        self::assertFalse($payload['success']);
        self::assertSame('No active account exists for this email address.', $payload['invalid']['email']);
    }

    public function testLoggedInUserStartsOwnCodeFlow(): void
    {
        $this->loginUser(self::TARGET_EMAIL);

        $crawler = $this->client->request('GET', '/profile/password-reset');
        self::assertSelectorNotExists('#reset-code input[name="email"]');

        $codeInputs = $crawler->filter('#reset-code input[name="_token"]');
        \assert($codeInputs->count() > 0);

        $this->client->request('POST', '/profile/password-reset/code', [
            '_token' => (string) $codeInputs->attr('value'),
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonPayload()['success']);

        [$token, $number] = $this->loadCodePageAndReadCode();

        $openRequest = $this->findResetRequest($token);
        self::assertSame(PasswordResetRequest::STATUS_CLAIMED, $openRequest->getStatus());
        self::assertNotNull($openRequest->getUser());
        self::assertSame(self::TARGET_EMAIL, $openRequest->getUser()->getEmail());
    }

    public function testStartWithUnknownEmailRedirectsWithDangerFlash(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/password-resets');

        $this->client->request('POST', '/admin/password-resets', [
            'email' => 'nobody@example.com',
            '_token' => $this->csrfInputValue($crawler),
        ]);
        self::assertResponseRedirects('/admin/password-resets');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'No active user found with this email address.');
    }

    public function testStartRequiresEmail(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/password-resets');

        $this->client->request('POST', '/admin/password-resets', [
            'email' => '',
            '_token' => $this->csrfInputValue($crawler),
        ]);
        self::assertResponseRedirects('/admin/password-resets');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Please enter an email address.');
    }

    public function testCompleteTwoKeyHandshake(): void
    {
        ['token' => $token, 'number' => $number] = $this->startReset();

        // The user presents the token by opening the flow page: pending -> claimed.
        $crawler = $this->openFlowPage($token);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-state="claimed"', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString($token, (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Present this token', (string) $crawler->text());

        $openRequest = $this->findResetRequest($token);
        self::assertSame(PasswordResetRequest::STATUS_CLAIMED, $openRequest->getStatus());

        // While the flow is not released yet, the password form stays hidden.
        self::assertStringNotContainsString('password-reset-flow-form', (string) $this->client->getResponse()->getContent());

        // A wrong release number is rejected and counted.
        $wrongNumber = $number === 9999 ? 1000 : $number + 1;
        $this->release($openRequest, $wrongNumber);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Incorrect release number.');

        // The correct number releases the flow.
        $this->release($openRequest, $number);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'The release has been granted.');

        // The user now sets the new password on the flow page.
        $this->openFlowPage($token);
        self::assertStringContainsString('data-state="released"', (string) $this->client->getResponse()->getContent());

        $this->submitPassword($token, self::NEW_PASSWORD, self::NEW_PASSWORD.'-mismatch');
        self::assertResponseStatusCodeSame(422);
        $payload = $this->jsonPayload();
        self::assertSame('The passwords do not match.', $payload['invalid']['password_confirm']);

        $this->submitPassword($token, self::NEW_PASSWORD, self::NEW_PASSWORD);
        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload();
        self::assertTrue($payload['success']);
        self::assertSame('/login', $payload['redirect']);

        $target = $this->findUser(self::TARGET_EMAIL);
        \assert(null !== $target);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        self::assertTrue($hasher->isPasswordValid($target, self::NEW_PASSWORD));
        self::assertFalse($hasher->isPasswordValid($target, self::TARGET_PASSWORD));

        $this->entityManager->clear();
        $completed = $this->findResetRequest($token);
        self::assertSame(PasswordResetRequest::STATUS_COMPLETED, $completed->getStatus());

        // The flow page renders the completed state afterwards.
        $this->openFlowPage($token);
        self::assertStringContainsString('data-state="completed"', (string) $this->client->getResponse()->getContent());
    }

    public function testReleaseIsRejectedBeforeTheUserClaimedTheToken(): void
    {
        ['token' => $token, 'number' => $number] = $this->startReset();

        $openRequest = $this->findResetRequest($token);
        self::assertSame(PasswordResetRequest::STATUS_PENDING, $openRequest->getStatus());

        $this->release($openRequest, $number);
        self::assertResponseRedirects('/admin/password-resets');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'This reset request is not waiting for a release.');
    }

    public function testReleaseInvalidatesTheRequestAfterTooManyWrongNumbers(): void
    {
        ['token' => $token, 'number' => $number] = $this->startReset();

        $this->openFlowPage($token);
        self::assertStringContainsString('data-state="claimed"', (string) $this->client->getResponse()->getContent());

        $openRequest = $this->findResetRequest($token);

        for ($attempt = 0; $attempt < PasswordResetRequest::MAX_NUMBER_ATTEMPTS; ++$attempt) {
            $wrongNumber = $number === 9999 ? 1000 : $number + 1;
            $this->release($openRequest, $wrongNumber);
            $this->client->followRedirect();
        }

        $this->entityManager->clear();
        $invalidated = $this->resetRepository->findByToken($token);
        \assert(null !== $invalidated);
        self::assertSame(PasswordResetRequest::STATUS_INVALIDATED, $invalidated->getStatus());

        // The flow page shows the expired state for an invalidated request.
        $this->openFlowPage($token);
        self::assertStringContainsString('data-state="expired"', (string) $this->client->getResponse()->getContent());
    }

    public function testExpiredPendingRequestShowsExpiredState(): void
    {
        ['token' => $token] = $this->startReset();

        $openRequest = $this->findResetRequest($token);
        $openRequest->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->entityManager->flush();

        $this->openFlowPage($token);
        self::assertStringContainsString('data-state="expired"', (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownTokenShowsInvalidState(): void
    {
        $crawler = $this->openFlowPage('BOGUS-TOKEN');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-state="unknown"', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('The reset process is invalid.', (string) $crawler->text());
    }

    public function testStatusEndpointReportsStatesAndCountdown(): void
    {
        ['token' => $token] = $this->startReset();

        $this->client->request('GET', '/profile/password-reset/admin-flow/'.$token.'/status');
        $payload = $this->jsonPayload();
        self::assertSame('pending', $payload['state']);
        self::assertGreaterThan(0, $payload['secondsLeft']);

        $this->client->request('GET', '/profile/password-reset/admin-flow/BOGUS-TOKEN/status');
        $payload = $this->jsonPayload();
        self::assertSame('unknown', $payload['state']);
        self::assertSame(0, $payload['secondsLeft']);
    }

    /**
     * Follows the client-side redirect of a successful code creation and
     * reads the one-time code (flow token and release number) from the page.
     *
     * @return array{string, int}
     */
    private function loadCodePageAndReadCode(): array
    {
        $this->client->request('GET', '/profile/password-reset');
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match('/<code class="fs-5">([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})<\/code>/s', $content, $tokenMatches));
        self::assertSame(1, preg_match('/<code class="fs-5">(\d{4})<\/code>/s', $content, $numberMatches));

        return [$tokenMatches[1], (int) $numberMatches[1]];
    }

    /**
     * Starts a reset for the target user as the administrator and returns the
     * plaintext token, the release number and the request id.
     *
     * @return array{token: string, number: int, id: string}
     */
    private function startReset(): array
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/password-resets');
        $this->client->request('POST', '/admin/password-resets', [
            'email' => self::TARGET_EMAIL,
            '_token' => $this->csrfInputValue($crawler),
        ]);
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match('/<code class="fs-5">([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})<\/code>/s', $content, $tokenMatches));
        self::assertSame(1, preg_match('/<code class="fs-5">(\d{4})<\/code>/s', $content, $numberMatches));

        $openRequest = $this->findResetRequest($tokenMatches[1]);
        $id = $openRequest->getId()?->toRfc4122() ?? '';
        self::assertNotSame('', $id);

        return [
            'token' => $tokenMatches[1],
            'number' => (int) $numberMatches[1],
            'id' => $id,
        ];
    }

    /**
     * Finds the reset request by its plaintext token with a cleared entity
     * manager, so the state written by previous web requests is visible (each
     * request reboots the kernel and works with its own entity manager).
     */
    private function findResetRequest(string $token): PasswordResetRequest
    {
        $this->entityManager->clear();
        $openRequest = $this->resetRepository->findByToken($token);
        \assert(null !== $openRequest);

        return $openRequest;
    }

    /**
     * Posts the release form for the request with the given number.
     */
    private function release(PasswordResetRequest $openRequest, int $number): void
    {
        $id = $openRequest->getId()?->toRfc4122() ?? '';

        $this->client->request('GET', '/admin/password-resets');
        $crawler = $this->client->getCrawler();
        $releaseInputs = $crawler->filter('form[action*="/'.$id.'/release"] input[name="_token"]');
        \assert($releaseInputs->count() > 0);
        $token = (string) $releaseInputs->attr('value');

        $this->client->request('POST', '/admin/password-resets/'.$id.'/release', [
            'number' => (string) $number,
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/admin/password-resets');
    }

    /**
     * Opens the user-facing flow page for the given token.
     */
    private function openFlowPage(string $token): Crawler
    {
        return $this->client->request('GET', '/profile/password-reset/admin-flow/'.$token);
    }

    /**
     * Posts the user-facing flow form with the given passwords.
     */
    private function submitPassword(string $token, string $password, string $passwordConfirm): void
    {
        $this->openFlowPage($token);
        $crawler = $this->client->getCrawler();
        $submitInputs = $crawler->filter('#password-reset-flow-form input[name="_token"]');
        \assert($submitInputs->count() > 0);
        $csrfToken = (string) $submitInputs->attr('value');

        $this->client->request('POST', '/profile/password-reset/admin-flow/'.$token, [
            'password' => $password,
            'password_confirm' => $passwordConfirm,
            '_token' => $csrfToken,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        \assert(is_array($payload));

        return $payload;
    }

    private function csrfInputValue(Crawler $crawler): string
    {
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        \assert(is_string($token) && '' !== $token);

        return $token;
    }

    private function loginAdmin(): void
    {
        $this->loginUser('admin@example.com');
    }

    private function loginUser(string $email): void
    {
        $user = $this->findUser($email);
        \assert(null !== $user);
        $this->client->loginUser($user);
    }

    private function findUser(string $email): ?User
    {
        $repository = $this->entityManager->getRepository(User::class);
        $user = $repository->findOneBy(['email' => $email]);
        \assert(null === $user || $user instanceof User);

        return $user;
    }

    private function seedData(): void
    {
        $admin = (new User())
            ->setEmail('admin@example.com')
            ->setPassword('admin-secret')
            ->setRoles(['ROLE_ADMIN']);

        $target = (new User())
            ->setEmail(self::TARGET_EMAIL)
            ->setPassword(self::TARGET_PASSWORD);

        $this->entityManager->persist($admin);
        $this->entityManager->persist($target);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}

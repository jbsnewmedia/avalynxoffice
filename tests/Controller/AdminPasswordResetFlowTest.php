<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\PasswordResetRequest;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Entity\UserLoginSession;
use AvalynxOffice\Repository\PasswordResetRequestRepository;
use AvalynxOffice\Repository\UserRepository;
use AvalynxOffice\Service\AdminPasswordResetService;
use AvalynxOffice\Service\Login\LoginSessionService;
use AvalynxOffice\Controller\UserPasswordResetController;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

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
        self::assertTrue($this->jsonPayload()['success']);
        $this->client->request('GET', '/admin/password-resets');
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
        self::assertTrue($this->jsonPayload()['success']);
        $this->client->request('GET', '/admin/password-resets');

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

    /**
     * Anonymous start for an unknown account: the response is identical to
     * the one for an existing account (success + decoy code on the page), and
     * no request is stored.
     */
    public function testUserStartWithUnknownEmailAnswersNeutrally(): void
    {
        $crawler = $this->client->request('GET', '/profile/password-reset');
        $codeInputs = $crawler->filter('#reset-code input[name="_token"]');
        \assert($codeInputs->count() > 0);

        $this->client->request('POST', '/profile/password-reset/code', [
            'email' => 'nobody@example.com',
            '_token' => (string) $codeInputs->attr('value'),
        ]);
        self::assertResponseIsSuccessful();

        $payload = $this->jsonPayload();
        self::assertTrue($payload['success']);
        self::assertSame('/profile/password-reset', $payload['redirect']);

        [$token] = $this->loadCodePageAndReadCode();

        $this->entityManager->clear();
        self::assertNull($this->resetRepository->findByToken($token));
        self::assertSame(0, $this->countResetRequests());
    }

    public function testUserStartForInactiveAccountAnswersNeutrally(): void
    {
        $target = $this->findUser(self::TARGET_EMAIL);
        \assert(null !== $target);
        $target->setActive(false);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/profile/password-reset');
        $codeInputs = $crawler->filter('#reset-code input[name="_token"]');
        \assert($codeInputs->count() > 0);

        $this->client->request('POST', '/profile/password-reset/code', [
            'email' => self::TARGET_EMAIL,
            '_token' => (string) $codeInputs->attr('value'),
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonPayload()['success']);

        [$token] = $this->loadCodePageAndReadCode();

        $this->entityManager->clear();
        self::assertNull($this->resetRepository->findByToken($token));
        self::assertSame(0, $this->countResetRequests());
    }

    /**
     * A second anonymous start shortly after the first one must not kill the
     * running handshake: the open request stays, the caller gets a decoy.
     */
    public function testRepeatedAnonymousStartKeepsYoungOpenRequest(): void
    {
        $crawler = $this->client->request('GET', '/profile/password-reset');
        $codeInputs = $crawler->filter('#reset-code input[name="_token"]');
        \assert($codeInputs->count() > 0);
        $csrf = (string) $codeInputs->attr('value');

        $this->client->request('POST', '/profile/password-reset/code', ['email' => self::TARGET_EMAIL, '_token' => $csrf]);
        self::assertResponseIsSuccessful();
        [$firstToken] = $this->loadCodePageAndReadCode();

        $this->client->request('POST', '/profile/password-reset/code', ['email' => self::TARGET_EMAIL, '_token' => $csrf]);
        self::assertResponseIsSuccessful();
        [$secondToken] = $this->loadCodePageAndReadCode();

        self::assertNotSame($firstToken, $secondToken);

        $first = $this->findResetRequest($firstToken);
        self::assertSame(PasswordResetRequest::STATUS_CLAIMED, $first->getStatus());
        self::assertNull($this->resetRepository->findByToken($secondToken));
        self::assertSame(1, $this->countResetRequests());
    }

    public function testAnonymousStartReplacesStaleOpenRequest(): void
    {
        $crawler = $this->client->request('GET', '/profile/password-reset');
        $codeInputs = $crawler->filter('#reset-code input[name="_token"]');
        \assert($codeInputs->count() > 0);
        $csrf = (string) $codeInputs->attr('value');

        $this->client->request('POST', '/profile/password-reset/code', ['email' => self::TARGET_EMAIL, '_token' => $csrf]);
        [$firstToken] = $this->loadCodePageAndReadCode();

        $first = $this->findResetRequest($firstToken);
        $first->setCreatedAt(new \DateTimeImmutable(sprintf('-%d seconds', AdminPasswordResetService::ANONYMOUS_REPLACE_AFTER_SECONDS + 60)));
        $this->entityManager->flush();

        $this->client->request('POST', '/profile/password-reset/code', ['email' => self::TARGET_EMAIL, '_token' => $csrf]);
        [$secondToken] = $this->loadCodePageAndReadCode();

        self::assertSame(PasswordResetRequest::STATUS_INVALIDATED, $this->findResetRequest($firstToken)->getStatus());
        self::assertSame(PasswordResetRequest::STATUS_CLAIMED, $this->findResetRequest($secondToken)->getStatus());
    }

    public function testAnonymousCodeStartIsRateLimitedPerIp(): void
    {
        $this->client->disableReboot();

        $crawler = $this->client->request('GET', '/profile/password-reset');
        $codeInputs = $crawler->filter('#reset-code input[name="_token"]');
        \assert($codeInputs->count() > 0);
        $csrf = (string) $codeInputs->attr('value');

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->client->request('POST', '/profile/password-reset/code', ['email' => 'nobody@example.com', '_token' => $csrf]);
            self::assertResponseIsSuccessful();
        }

        $this->client->request('POST', '/profile/password-reset/code', ['email' => 'nobody@example.com', '_token' => $csrf]);
        self::assertResponseStatusCodeSame(429);
        self::assertFalse($this->jsonPayload()['success']);
    }

    public function testStatusEndpointIsRateLimitedPerIp(): void
    {
        $this->client->disableReboot();

        $limiter = static::getContainer()->get('limiter.password_reset_status');
        \assert($limiter instanceof RateLimiterFactoryInterface);
        // Exhaust the budget of the test client's IP directly.
        $limiter->create('127.0.0.1')->consume(30);

        $this->client->request('GET', '/profile/password-reset/admin-flow/UNKNOWN-TOKEN/status');
        self::assertResponseStatusCodeSame(429);

        $payload = $this->jsonPayload();
        self::assertSame('unknown', $payload['state']);
        self::assertArrayHasKey('error', $payload);
    }

    public function testCompletedFlowRevokesLoginSessionsAndOtherOpenRequests(): void
    {
        $target = $this->findUser(self::TARGET_EMAIL);
        \assert(null !== $target);

        $this->seedLoginSession($target, 'device-a');
        $this->seedLoginSession($target, 'device-b');

        ['token' => $token, 'number' => $number] = $this->startReset();
        $this->openFlowPage($token);

        // A second open request (e.g. started by the administrator again)
        // that was not superseded must be invalidated by the completion.
        $other = new PasswordResetRequest();
        $other->setUser($this->findUser(self::TARGET_EMAIL) ?? $target);
        $other->setTokenHash(hash('sha256', 'OTHER-TOKEN'));
        $other->setReleaseNumberHash(hash('sha256', 'x'));
        $other->setStatus(PasswordResetRequest::STATUS_PENDING);
        $other->setExpiresAt(new \DateTimeImmutable('+1 hour'));
        $other->setCreatedIp('127.0.0.1');
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $openRequest = $this->findResetRequest($token);
        $this->release($openRequest, $number);
        self::assertTrue($this->jsonPayload()['success']);

        $this->submitPassword($token, self::NEW_PASSWORD, self::NEW_PASSWORD);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame(PasswordResetRequest::STATUS_COMPLETED, $this->findResetRequest($token)->getStatus());
        self::assertSame(PasswordResetRequest::STATUS_INVALIDATED, $this->findResetRequest('OTHER-TOKEN')->getStatus());

        $refreshed = $this->findUser(self::TARGET_EMAIL);
        \assert(null !== $refreshed);
        $rows = $this->entityManager->getRepository(UserLoginSession::class)->findBy(['user' => $refreshed->getId()]);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertNotNull($row->getRevokedAt());
        }
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

    public function testStartWithUnknownEmailAnswersInvalidField(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/password-resets');

        $this->client->request('POST', '/admin/password-resets', [
            'email' => 'nobody@example.com',
            '_token' => $this->csrfInputValue($crawler),
        ]);
        self::assertResponseStatusCodeSame(422);

        $payload = $this->jsonPayload();
        self::assertFalse($payload['success']);
        self::assertSame('No active user found with this email address.', $payload['invalid']['email']);
    }

    public function testStartRequiresEmail(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/password-resets');

        $this->client->request('POST', '/admin/password-resets', [
            'email' => '',
            '_token' => $this->csrfInputValue($crawler),
        ]);
        self::assertResponseStatusCodeSame(422);

        $payload = $this->jsonPayload();
        self::assertFalse($payload['success']);
        self::assertSame('Please enter an email address.', $payload['invalid']['email']);
    }

    public function testStartRejectsInvalidCsrfToken(): void
    {
        $this->loginAdmin();

        $this->client->request('POST', '/admin/password-resets', [
            'email' => self::TARGET_EMAIL,
            '_token' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->jsonPayload()['success']);
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
        self::assertResponseStatusCodeSame(422);
        $payload = $this->jsonPayload();
        self::assertFalse($payload['success']);
        self::assertSame('The release was not possible. Check the release number and the state of the request.', $payload['invalid']['number']);

        // The correct number releases the flow.
        $this->release($openRequest, $number);
        self::assertTrue($this->jsonPayload()['success']);
        $this->client->request('GET', '/admin/password-resets');
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
        self::assertResponseStatusCodeSame(422);

        $payload = $this->jsonPayload();
        self::assertFalse($payload['success']);
        // Generic message: the internal state reason is only logged.
        self::assertSame('The release was not possible. Check the release number and the state of the request.', $payload['invalid']['number']);
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
            self::assertResponseStatusCodeSame(422);
            self::assertSame('The release was not possible. Check the release number and the state of the request.', $this->jsonPayload()['invalid']['number']);
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

    public function testStatusEndpointReportsExpiredForStaleRequests(): void
    {
        ['token' => $token] = $this->startReset();

        $openRequest = $this->findResetRequest($token);
        $openRequest->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->entityManager->flush();

        $this->client->request('GET', '/profile/password-reset/admin-flow/'.$token.'/status');
        $payload = $this->jsonPayload();
        self::assertSame('expired', $payload['state']);
        self::assertSame(0, $payload['secondsLeft']);
    }

    public function testSubmitPasswordRejectsInvalidCsrfToken(): void
    {
        ['token' => $token] = $this->startReset();
        $this->openFlowPage($token);

        $this->client->request('POST', '/profile/password-reset/admin-flow/'.$token, [
            'password' => self::NEW_PASSWORD,
            'password_confirm' => self::NEW_PASSWORD,
            '_token' => 'garbage',
        ]);

        self::assertResponseStatusCodeSame(403);
        $payload = $this->jsonPayload();
        self::assertFalse($payload['success']);
        self::assertArrayHasKey('message', $payload);
    }

    public function testSubmitPasswordRequiresNonEmptyPassword(): void
    {
        ['token' => $token, 'number' => $number] = $this->startReset();
        $this->openFlowPage($token);

        $openRequest = $this->findResetRequest($token);
        $this->release($openRequest, $number);
        self::assertTrue($this->jsonPayload()['success']);

        $this->submitPassword($token, '', '');

        self::assertResponseStatusCodeSame(422);
        $payload = $this->jsonPayload();
        self::assertFalse($payload['success']);
        self::assertArrayHasKey('password', $payload['invalid']);
    }

    public function testSubmitPasswordFailsForUnknownToken(): void
    {
        ['token' => $token, 'number' => $number] = $this->startReset();
        $this->openFlowPage($token);

        $openRequest = $this->findResetRequest($token);
        $this->release($openRequest, $number);
        self::assertTrue($this->jsonPayload()['success']);

        // The CSRF token of a rendered flow page is not bound to the token.
        $crawler = $this->openFlowPage($token);
        $submitInputs = $crawler->filter('#password-reset-flow-form input[name="_token"]');
        \assert($submitInputs->count() > 0);
        $csrfToken = (string) $submitInputs->attr('value');

        $this->client->request('POST', '/profile/password-reset/admin-flow/BOGUS-TOKEN', [
            'password' => self::NEW_PASSWORD,
            'password_confirm' => self::NEW_PASSWORD,
            '_token' => $csrfToken,
        ]);

        $payload = $this->jsonPayload();
        self::assertFalse($payload['success']);
        self::assertSame('The reset process is invalid.', $payload['message']);
    }

    public function testSubmitPasswordFailsWhenTheReleaseWindowExpired(): void
    {
        ['token' => $token, 'number' => $number] = $this->startReset();
        $this->openFlowPage($token);

        $openRequest = $this->findResetRequest($token);
        $this->release($openRequest, $number);
        self::assertTrue($this->jsonPayload()['success']);

        $released = $this->findResetRequest($token);
        $released->setReleaseExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->entityManager->flush();

        $this->submitPassword($token, self::NEW_PASSWORD, self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(403);
        $payload = $this->jsonPayload();
        self::assertFalse($payload['success']);
        self::assertSame('The reset process has expired or has been invalidated.', $payload['message']);
    }

    public function testFlowKeepsPendingStateWhenTheClaimLosesARace(): void
    {
        $pending = new PasswordResetRequest();
        $pending->setStatus(PasswordResetRequest::STATUS_PENDING);
        $pending->setExpiresAt(new \DateTimeImmutable('+1 hour'));

        // The flow looks the request up, then the service claims it with a
        // second lookup - when that lookup comes up empty (the request was
        // superseded in between), the flow must keep the pending state.
        $repository = $this->createMock(PasswordResetRequestRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findByToken')
            ->willReturnOnConsecutiveCalls($pending, null);

        $service = new AdminPasswordResetService(
            $repository,
            $this->createStub(UserRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(UserPasswordHasherInterface::class),
        );

        $controller = new UserPasswordResetController($service, $repository, static::getContainer()->get('translator'), static::getContainer()->get('limiter.password_reset_status'));
        $controller->setContainer(static::getContainer());

        $request = Request::create('/profile/password-reset/admin-flow/race-token');
        static::getContainer()->get('request_stack')->push($request);

        $response = $controller->flow('race-token');

        self::assertStringContainsString('data-state="pending"', (string) $response->getContent());
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

        $payload = $this->jsonPayload();
        self::assertTrue($payload['success']);
        self::assertSame('/admin/password-resets', $payload['redirect']);

        // The client follows the redirect: the GET page renders the freshly
        // created code exactly once from the session.
        $this->client->request('GET', '/admin/password-resets');
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match('/<code class="fs-5">([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})<\/code>/s', $content, $tokenMatches));
        self::assertSame(1, preg_match('/<code class="fs-5">(\d{4})<\/code>/s', $content, $numberMatches));

        // Reloading the page must not show the code again. The check runs
        // against the visible page text: raw HTML would also match random
        // asset version hashes that happen to contain the number.
        $this->client->request('GET', '/admin/password-resets');
        $reloadCrawler = $this->client->getCrawler();
        self::assertSame(0, $reloadCrawler->filter('code.fs-5')->count());
        self::assertStringNotContainsString((string) $numberMatches[1], $reloadCrawler->text());

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

    private function countResetRequests(): int
    {
        $count = $this->resetRepository->createQueryBuilder('r')->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        return (int) $count;
    }

    private function seedLoginSession(User $user, string $token): void
    {
        $row = new UserLoginSession()
            ->setUser($user)
            ->setSessionToken(LoginSessionService::hashToken($token))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setLastSeenAt(new \DateTimeImmutable());
        $this->entityManager->persist($row);
        $this->entityManager->flush();
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

    public function testReleaseWithInvalidTokenAnswersFailure(): void
    {
        $this->loginAdmin();

        $this->client->request('POST', '/admin/password-resets/00000000-0000-7000-8000-000000000000/release', [
            'number' => '123456',
            '_token' => 'not-a-valid-token',
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
        self::assertSame('Invalid token.', $payload['message']);
    }

    public function testReleaseWithUnknownRequestAnswersFailure(): void
    {
        $this->loginAdmin();

        $unknownId = '00000000-0000-7000-8000-000000000000';
        $this->client->request('GET', '/admin/password-resets');
        $session = $this->client->getRequest()->getSession();
        $value = 'test-csrf-token-'.bin2hex(random_bytes(8));
        $session->set('_csrf/admin_password_reset_release_'.$unknownId, $value);
        $session->save();

        $this->client->request('POST', '/admin/password-resets/'.$unknownId.'/release', [
            'number' => '123456',
            '_token' => $value,
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
        self::assertSame('The request was not found or has already expired.', $payload['message']);
    }

    public function testClientIpFallsBackToUnknown(): void
    {
        $controller = static::getContainer()->get(\AvalynxOffice\Controller\AdminPasswordResetController::class);
        \assert($controller instanceof \AvalynxOffice\Controller\AdminPasswordResetController);

        $method = new \ReflectionMethod($controller, 'clientIp');
        self::assertSame('127.0.0.1', $method->invoke($controller, new \Symfony\Component\HttpFoundation\Request(server: ['REMOTE_ADDR' => '127.0.0.1'])));
        self::assertSame('unknown', $method->invoke($controller, new \Symfony\Component\HttpFoundation\Request()));
    }

    public function testAdminIdRejectsUserWithoutIdentifier(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $idLess = new User();
        $idLess->setEmail('idless@example.com');
        $idLess->setPassword($hasher->hashPassword($idLess, 'secret'));

        $property = new \ReflectionProperty(User::class, 'id');
        $property->setValue($idLess, null);

        $this->client->loginUser($idLess);

        $controller = static::getContainer()->get(\AvalynxOffice\Controller\AdminPasswordResetController::class);
        \assert($controller instanceof \AvalynxOffice\Controller\AdminPasswordResetController);
        $method = new \ReflectionMethod($controller, 'adminId');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Administrator account has no identifier.');
        $method->invoke($controller);
    }
}

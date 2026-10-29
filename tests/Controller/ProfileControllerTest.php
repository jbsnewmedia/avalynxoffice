<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Controller\ProfileController;
use AvalynxOffice\Entity\RecoveryAddress;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Service\UserService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpFoundation\UriSigner;

#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('functional')]
final class ProfileControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
        $this->seedUsers();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
    }

    public function testProfileOverviewRendersNeutralDashboard(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="profileNavDropdown"', $content);
        self::assertStringContainsString('href="/profile/account"', $content);
        self::assertStringContainsString('href="/profile/recovery"', $content);
        // The overview is a neutral dashboard: no account data, no forms.
        self::assertStringNotContainsString('user@example.com', $content);
        self::assertStringNotContainsString('/profile/recovery/add', $content);
    }

    public function testProfileAccountPageRendersAccountData(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/profile/account');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="profileNavDropdown"', $content);
        self::assertStringContainsString('user@example.com', $content);
        self::assertStringNotContainsString('/profile/recovery/add', $content);
    }

    public function testProfileRecoveryPageRendersRecoverySection(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/profile/recovery');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="profileNavDropdown"', $content);
        self::assertStringContainsString('/profile/recovery/add', $content);
        self::assertStringNotContainsString('user@example.com', $content);
    }

    public function testRecoveryAddRejectsInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/recovery/add', ['_token' => 'invalid', 'email' => 'backup@example.com']);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertCount(0, $refreshed->getRecoveryAddresses());
    }

    public function testRecoveryAddRequiresEmail(): void
    {
        $token = $this->setCsrfToken('add_recovery');
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/recovery/add', ['_token' => $token, 'email' => '   ']);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('email', $data['invalid']);
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertCount(0, $refreshed->getRecoveryAddresses());
    }

    public function testRecoveryAddRejectsInvalidEmail(): void
    {
        $token = $this->setCsrfToken('add_recovery');
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/recovery/add', ['_token' => $token, 'email' => 'not-an-email']);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('Please enter a valid email address.', $data['invalid']['email']);
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertCount(0, $refreshed->getRecoveryAddresses());
    }

    public function testRecoveryAddStoresAddress(): void
    {
        $token = $this->setCsrfToken('add_recovery');
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/recovery/add', ['_token' => $token, 'email' => 'Backup@Example.com']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        $addresses = $refreshed->getRecoveryAddresses();
        self::assertCount(1, $addresses);
        self::assertSame('backup@example.com', $addresses->first()?->getEmail());
    }

    public function testRecoveryAddRejectsDuplicate(): void
    {
        $this->createRecoveryAddress($this->user, 'backup@example.com');
        $token = $this->setCsrfToken('add_recovery');
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/recovery/add', ['_token' => $token, 'email' => 'backup@example.com']);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertCount(1, $refreshed->getRecoveryAddresses());
    }

    public function testRecoveryRemoveReturnsNotFoundForForeignAddress(): void
    {
        $adminAddress = $this->createRecoveryAddress($this->admin, 'admin-backup@example.com');
        $token = $this->setCsrfToken('remove_recovery_'.$adminAddress->getId());

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/recovery/remove/'.($adminAddress->getId()?->toRfc4122() ?? ''), ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRecoveryRemoveReturnsNotFoundForUnknownAddress(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/recovery/remove/00000000-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRecoveryRemoveRejectsInvalidCsrfToken(): void
    {
        $address = $this->createRecoveryAddress($this->user, 'backup@example.com');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/recovery/remove/'.($address->getId()?->toRfc4122() ?? ''), ['_token' => 'invalid']);

        self::assertResponseRedirects('/profile/recovery');
        $this->em->clear();
        self::assertNotNull($this->em->find(RecoveryAddress::class, $address->getId()));
    }

    public function testRecoveryRemoveDetachesAddress(): void
    {
        $address = $this->createRecoveryAddress($this->user, 'backup@example.com');
        $token = $this->setCsrfToken('remove_recovery_'.$address->getId());

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/recovery/remove/'.($address->getId()?->toRfc4122() ?? ''), ['_token' => $token]);

        self::assertResponseRedirects('/profile/recovery');
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertCount(0, $refreshed->getRecoveryAddresses());
    }

    public function testPasswordResetRequestFormIsPublic(): void
    {
        $this->client->request('GET', '/profile/password-reset');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('name="email"', $content);
        self::assertStringContainsString('name="_token"', $content);
    }

    public function testPasswordResetSendRejectsInvalidCsrfToken(): void
    {
        $this->client->request('POST', '/profile/password-reset', ['_token' => 'invalid', 'email' => 'user@example.com']);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('Invalid form token. Please try again.', $data['message']);
    }

    public function testPasswordResetSendRequiresEmail(): void
    {
        $token = $this->setCsrfToken('password_reset_request');
        $this->client->request('POST', '/profile/password-reset', ['_token' => $token, 'email' => '']);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('email', $data['invalid']);
    }

    public function testPasswordResetSendRejectsInvalidEmail(): void
    {
        $token = $this->setCsrfToken('password_reset_request');
        $this->client->request('POST', '/profile/password-reset', ['_token' => $token, 'email' => 'not-an-email']);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('Please enter a valid email address.', $data['invalid']['email']);
    }

    public function testPasswordResetSendAnswersGenericSuccessForUnknownUser(): void
    {
        $token = $this->setCsrfToken('password_reset_request');
        $this->client->request('POST', '/profile/password-reset', ['_token' => $token, 'email' => 'unknown@example.com']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame('/profile/password-reset', $data['redirect']);

        $this->client->request('GET', $data['redirect']);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('If recovery addresses are registered', $content);
        self::assertStringContainsString('Back to sign in', $content);
    }

    public function testPasswordResetSendAnswersGenericSuccessWithoutAddresses(): void
    {
        $token = $this->setCsrfToken('password_reset_request');
        $this->client->request('POST', '/profile/password-reset', ['_token' => $token, 'email' => 'user@example.com']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame('/profile/password-reset', $data['redirect']);

        $this->client->request('GET', $data['redirect']);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('If recovery addresses are registered', $content);
        self::assertStringContainsString('Back to sign in', $content);
    }

    public function testPasswordResetSendAnswersGenericSuccessWithRecoveryAddress(): void
    {
        $this->createRecoveryAddress($this->user, 'backup@example.com');
        $token = $this->setCsrfToken('password_reset_request');
        $this->client->request('POST', '/profile/password-reset', ['_token' => $token, 'email' => 'user@example.com']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame('/profile/password-reset', $data['redirect']);

        $this->client->request('GET', $data['redirect']);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('If recovery addresses are registered', $content);
        self::assertStringContainsString('Back to sign in', $content);
    }

    public function testPasswordResetExecuteRejectsInvalidSignature(): void
    {
        $this->client->request('GET', '/profile/password-reset/execute/'.($this->user->getId()?->toRfc4122() ?? ''));

        self::assertResponseStatusCodeSame(404);
    }

    public function testPasswordResetExecuteReturnsNotFoundForUnknownUser(): void
    {
        $url = $this->signUrl('/profile/password-reset/execute/00000000-0000-7000-8000-000000000000');

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function testPasswordResetExecuteRendersResetFormWithSignedSubmitUrl(): void
    {
        $url = $this->signUrl('/profile/password-reset/execute/'.($this->user->getId()?->toRfc4122() ?? ''));

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('passwordResetForm', $content);
        self::assertStringContainsString('_hash=', $content);
    }

    public function testPasswordResetSubmitRejectsInvalidSignature(): void
    {
        $this->client->request('POST', '/profile/password-reset/submit/'.($this->user->getId()?->toRfc4122() ?? ''), ['password' => 'new-secret']);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
    }

    public function testPasswordResetSubmitReturnsNotFoundForUnknownUser(): void
    {
        $url = $this->signUrl('/profile/password-reset/submit/00000000-0000-7000-8000-000000000000');

        $this->client->request('POST', $url, ['password' => 'new-secret', 'password_confirm' => 'new-secret']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testPasswordResetCodeStartRejectsInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/password-reset/code', ['_token' => 'invalid', 'email' => 'target@example.com']);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
    }

    public function testSignedRequestCheckWithoutActiveRequestReturnsFalse(): void
    {
        $controller = static::getContainer()->get(ProfileController::class);
        \assert($controller instanceof ProfileController);

        $method = new \ReflectionMethod(ProfileController::class, 'isSignedRequestValid');

        self::assertFalse($method->invoke($controller));
    }

    public function testPasswordResetSubmitReRendersFormForEmptyPassword(): void
    {
        $url = $this->signUrl('/profile/password-reset/submit/'.($this->user->getId()?->toRfc4122() ?? ''));

        $this->client->request('POST', $url, ['password' => '']);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('password', $data['invalid']);
    }

    public function testPasswordResetSubmitRejectsTooShortPassword(): void
    {
        $url = $this->signUrl('/profile/password-reset/submit/'.($this->user->getId()?->toRfc4122() ?? ''));

        $this->client->request('POST', $url, ['password' => 'short', 'password_confirm' => 'short']);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('The password must be at least 8 characters long.', $data['invalid']['password']);

        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($refreshed, 'user'));
    }

    public function testPasswordResetSubmitRejectsMismatchedConfirmation(): void
    {
        $url = $this->signUrl('/profile/password-reset/submit/'.($this->user->getId()?->toRfc4122() ?? ''));

        $this->client->request('POST', $url, ['password' => 'new-secret', 'password_confirm' => 'other-secret']);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('The passwords do not match.', $data['invalid']['password_confirm']);

        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($refreshed, 'user'));
    }

    public function testPasswordResetSubmitStoresHashedPassword(): void
    {
        $url = $this->signUrl('/profile/password-reset/submit/'.($this->user->getId()?->toRfc4122() ?? ''));

        $this->client->request('POST', $url, ['password' => 'new-secret', 'password_confirm' => 'new-secret']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($refreshed, 'new-secret'));
    }

    private function signUrl(string $path): string
    {
        $signer = static::getContainer()->get(UriSigner::class);
        $signed = $signer->sign('http://localhost'.$path);

        return (string) (parse_url($signed, PHP_URL_PATH) ?? $path)
            .'?'.(parse_url($signed, PHP_URL_QUERY) ?? '');
    }

    private function createRecoveryAddress(User $owner, string $email): RecoveryAddress
    {
        $service = static::getContainer()->get(UserService::class);
        $address = $service->addRecoveryAddress($owner, $email);
        $this->em->flush();
        $this->em->refresh($owner);

        return $address;
    }

    private function setCsrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/login');
        $session = $this->client->getRequest()->getSession();
        $value = 'test-csrf-token-'.bin2hex(random_bytes(8));
        $session->set('_csrf/'.$tokenId, $value);
        $session->save();

        return $value;
    }

    private function seedUsers(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->setEmail('admin@example.com');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword($hasher->hashPassword($this->admin, 'admin'));
        $this->admin->setLastAuthAt(new \DateTimeImmutable('-1 hour'));

        $this->user = new User();
        $this->user->setEmail('user@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'user'));
        $this->user->setLastAuthAt(new \DateTimeImmutable('-1 hour'));

        $this->em->persist($this->admin);
        $this->em->persist($this->user);
        $this->em->flush();
    }
}

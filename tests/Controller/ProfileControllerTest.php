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
use AvalynxOffice\Entity\UserLoginSession;
use AvalynxOffice\Service\Login\LoginSessionService;
use AvalynxOffice\Service\UserService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

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
        $address = $addresses->first();
        self::assertInstanceOf(RecoveryAddress::class, $address);
        self::assertSame('backup@example.com', $address->getEmail());

        // Double opt-in: the address starts unverified with a pending token
        // and the confirmation link is mailed to the new address.
        self::assertFalse($address->isVerified());
        self::assertSame([], $refreshed->getVerifiedRecoveryAddresses());
        self::assertIsString($address->getVerificationTokenHash());
        self::assertSame(64, strlen($address->getVerificationTokenHash()));
        self::assertGreaterThan(new \DateTimeImmutable('+23 hours'), $address->getVerificationExpiresAt());

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'backup@example.com');
        self::assertEmailTextBodyContains($email, 'http://localhost/profile/recovery/verify/'.($address->getId()?->toRfc4122() ?? '').'/');
        self::assertEmailTextBodyContains($email, 'user@example.com');
        // The mail must not leak the stored hash.
        self::assertEmailTextBodyNotContains($email, $address->getVerificationTokenHash());
    }

    public function testRecoveryAddIsRateLimitedPerIp(): void
    {
        $this->client->disableReboot();
        $this->client->loginUser($this->user);

        // The "password_reset" limiter allows 5 attempts per 15 minutes. Every
        // syntactically valid attempt counts, even once the per-user address
        // limit (MAX_RECOVERY_ADDRESSES) rejects further additions.
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $token = $this->setCsrfToken('add_recovery');
            $this->client->request('POST', '/profile/recovery/add', ['_token' => $token, 'email' => sprintf('backup%d@example.com', $attempt)]);
            if ($attempt < UserService::MAX_RECOVERY_ADDRESSES) {
                self::assertResponseIsSuccessful();
                // Services (incl. the mailer logger) are reset between requests.
                self::assertEmailCount(1);
            } else {
                self::assertResponseStatusCodeSame(422);
                self::assertEmailCount(0);
            }
        }

        $token = $this->setCsrfToken('add_recovery');
        $this->client->request('POST', '/profile/recovery/add', ['_token' => $token, 'email' => 'backup-blocked@example.com']);

        self::assertResponseStatusCodeSame(429);
        self::assertEmailCount(0);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('Too many requests. Please try again in a few minutes.', $data['message']);

        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertCount(UserService::MAX_RECOVERY_ADDRESSES, $refreshed->getRecoveryAddresses());
    }

    public function testRecoveryPageShowsVerificationStatusAndResendButton(): void
    {
        $this->createRecoveryAddress($this->user, 'confirmed@example.com');
        [$pending] = $this->createPendingRecoveryAddress($this->user, 'pending@example.com');

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/profile/recovery');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Verified', $content);
        self::assertStringContainsString('Unverified', $content);
        self::assertStringContainsString('/profile/recovery/resend/'.($pending->getId()?->toRfc4122() ?? ''), $content);
        self::assertSame(1, substr_count($content, '/profile/recovery/resend/'));
    }

    public function testRecoveryVerifyWithValidTokenMarksAddressVerified(): void
    {
        [$address, $token] = $this->createPendingRecoveryAddress($this->user, 'pending@example.com');

        // The link works without being logged in.
        $this->client->request('GET', $this->verifyPath($address, $token));

        self::assertResponseRedirects('/login');
        $this->em->clear();
        $refreshed = $this->em->find(RecoveryAddress::class, $address->getId());
        self::assertInstanceOf(RecoveryAddress::class, $refreshed);
        self::assertTrue($refreshed->isVerified());
        self::assertNull($refreshed->getVerificationTokenHash());
        self::assertNull($refreshed->getVerificationExpiresAt());

        // The link is single-use.
        $this->client->request('GET', $this->verifyPath($address, $token));
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertStringContainsString('invalid or has expired', (string) $this->client->getResponse()->getContent());
    }

    public function testRecoveryVerifyRedirectsLoggedInUserToRecoveryPage(): void
    {
        [$address, $token] = $this->createPendingRecoveryAddress($this->user, 'pending@example.com');

        $this->client->loginUser($this->user);
        $this->client->request('GET', $this->verifyPath($address, $token));

        self::assertResponseRedirects('/profile/recovery');
        $this->client->followRedirect();
        self::assertStringContainsString('Recovery address verified.', (string) $this->client->getResponse()->getContent());
    }

    public function testRecoveryVerifyRejectsWrongToken(): void
    {
        [$address, $token] = $this->createPendingRecoveryAddress($this->user, 'pending@example.com');

        $this->client->request('GET', $this->verifyPath($address, strrev($token)));

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertStringContainsString('invalid or has expired', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        $refreshed = $this->em->find(RecoveryAddress::class, $address->getId());
        self::assertInstanceOf(RecoveryAddress::class, $refreshed);
        self::assertFalse($refreshed->isVerified());
        self::assertNotNull($refreshed->getVerificationTokenHash());
    }

    public function testRecoveryVerifyRejectsExpiredToken(): void
    {
        [$address, $token] = $this->createPendingRecoveryAddress($this->user, 'pending@example.com');
        $address->setVerificationExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $this->client->request('GET', $this->verifyPath($address, $token));

        self::assertResponseRedirects('/login');
        $this->em->clear();
        $refreshed = $this->em->find(RecoveryAddress::class, $address->getId());
        self::assertInstanceOf(RecoveryAddress::class, $refreshed);
        self::assertFalse($refreshed->isVerified());
    }

    /**
     * Unknown ids (valid or malformed) answer exactly like a wrong token.
     */
    public function testRecoveryVerifyAnswersNeutrallyForUnknownAddress(): void
    {
        $this->client->request('GET', '/profile/recovery/verify/00000000-0000-7000-8000-000000000000/some-token');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/profile/recovery/verify/not-a-uuid/some-token');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertStringContainsString('invalid or has expired', (string) $this->client->getResponse()->getContent());
    }

    public function testRecoveryResendSendsFreshVerificationMail(): void
    {
        [$address, $token] = $this->createPendingRecoveryAddress($this->user, 'pending@example.com');
        $previousHash = $address->getVerificationTokenHash();
        $csrf = $this->setCsrfToken('resend_recovery_'.$address->getId());

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/recovery/resend/'.($address->getId()?->toRfc4122() ?? ''), ['_token' => $csrf]);

        self::assertResponseRedirects('/profile/recovery');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'pending@example.com');
        self::assertEmailTextBodyContains($email, '/profile/recovery/verify/'.($address->getId()?->toRfc4122() ?? '').'/');

        // The previous token has been superseded.
        $this->em->clear();
        $refreshed = $this->em->find(RecoveryAddress::class, $address->getId());
        self::assertInstanceOf(RecoveryAddress::class, $refreshed);
        self::assertFalse($refreshed->isVerified());
        self::assertNotSame($previousHash, $refreshed->getVerificationTokenHash());
        self::assertNotSame(hash('sha256', $token), $refreshed->getVerificationTokenHash());
    }

    public function testRecoveryResendRejectsInvalidCsrfToken(): void
    {
        [$address] = $this->createPendingRecoveryAddress($this->user, 'pending@example.com');
        $previousHash = $address->getVerificationTokenHash();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/recovery/resend/'.($address->getId()?->toRfc4122() ?? ''), ['_token' => 'invalid']);

        self::assertResponseRedirects('/profile/recovery');
        self::assertEmailCount(0);
        $this->em->clear();
        $refreshed = $this->em->find(RecoveryAddress::class, $address->getId());
        self::assertInstanceOf(RecoveryAddress::class, $refreshed);
        self::assertSame($previousHash, $refreshed->getVerificationTokenHash());
    }

    public function testRecoveryResendReturnsNotFoundForForeignAddress(): void
    {
        [$address] = $this->createPendingRecoveryAddress($this->admin, 'admin-pending@example.com');
        $csrf = $this->setCsrfToken('resend_recovery_'.$address->getId());

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/profile/recovery/resend/'.($address->getId()?->toRfc4122() ?? ''), ['_token' => $csrf]);

        self::assertResponseStatusCodeSame(404);
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
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'backup@example.com');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame('/profile/password-reset', $data['redirect']);

        $this->client->request('GET', $data['redirect']);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('If recovery addresses are registered', $content);
        self::assertStringContainsString('Back to sign in', $content);
    }

    /**
     * An unconfirmed address must never receive a reset link - the answer
     * stays the generic one so nothing about the account leaks.
     */
    public function testPasswordResetSendIgnoresUnverifiedRecoveryAddress(): void
    {
        $this->createPendingRecoveryAddress($this->user, 'pending@example.com');
        $token = $this->setCsrfToken('password_reset_request');
        $this->client->request('POST', '/profile/password-reset', ['_token' => $token, 'email' => 'user@example.com']);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame('/profile/password-reset', $data['redirect']);
    }

    public function testPasswordResetSendOnlyTargetsVerifiedRecoveryAddresses(): void
    {
        $this->createRecoveryAddress($this->user, 'confirmed@example.com');
        $this->createPendingRecoveryAddress($this->user, 'pending@example.com');
        $token = $this->setCsrfToken('password_reset_request');
        $this->client->request('POST', '/profile/password-reset', ['_token' => $token, 'email' => 'user@example.com']);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'confirmed@example.com');
        self::assertEmailAddressNotContains($email, 'To', 'pending@example.com');
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
        $url = $this->signUrl('/profile/password-reset/execute/'.($this->user->getId()?->toRfc4122() ?? ''), $this->user);

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('passwordResetForm', $content);
        self::assertStringContainsString('_hash=', $content);
        self::assertStringContainsString(UserService::RESET_FINGERPRINT_PARAMETER.'=', $content);
    }

    public function testPasswordResetExecuteRejectsLinkWithoutPasswordFingerprint(): void
    {
        $url = $this->signUrl('/profile/password-reset/execute/'.($this->user->getId()?->toRfc4122() ?? ''));

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function testPasswordResetSubmitRejectsLinkWithoutPasswordFingerprint(): void
    {
        $url = $this->signUrl('/profile/password-reset/submit/'.($this->user->getId()?->toRfc4122() ?? ''));

        $this->client->request('POST', $url, ['password' => 'new-secret', 'password_confirm' => 'new-secret']);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($refreshed, 'user'));
    }

    public function testPasswordResetExecuteRendersSubmitUrlThatIsStillValid(): void
    {
        $url = $this->signUrl('/profile/password-reset/execute/'.($this->user->getId()?->toRfc4122() ?? ''), $this->user);

        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        // The submit URL rendered into the form carries an expiration in the
        // future (a relative TTL, not a 1970 timestamp) and verifies.
        $submitUrl = $crawler->filter('#passwordResetForm')->attr('action');
        self::assertIsString($submitUrl);
        parse_str((string) parse_url($submitUrl, PHP_URL_QUERY), $query);
        self::assertGreaterThan(time(), (int) ($query['_expiration'] ?? 0));
        self::assertTrue(static::getContainer()->get(UriSigner::class)->check($submitUrl));
    }

    public function testPasswordResetLinkBecomesInvalidAfterPasswordChange(): void
    {
        $userId = $this->user->getId()?->toRfc4122() ?? '';
        $executeUrl = $this->signUrl('/profile/password-reset/execute/'.$userId, $this->user);
        $submitUrl = $this->signUrl('/profile/password-reset/submit/'.$userId, $this->user);

        // First use: the link works and the password is changed.
        $this->client->request('POST', $submitUrl, ['password' => 'new-secret', 'password_confirm' => 'new-secret']);
        self::assertResponseIsSuccessful();

        // Second use of the very same (still validly signed) links: rejected.
        $this->client->request('GET', $executeUrl);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', $submitUrl, ['password' => 'other-secret', 'password_confirm' => 'other-secret']);
        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);

        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $refreshed);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($refreshed, 'new-secret'));
    }

    public function testPasswordResetSubmitRevokesAllLoginSessionsAndRememberMeTokens(): void
    {
        $this->seedLoginSession($this->user, 'device-a');
        $this->seedLoginSession($this->user, 'device-b');
        $this->seedRememberMeToken($this->user);

        $url = $this->signUrl('/profile/password-reset/submit/'.($this->user->getId()?->toRfc4122() ?? ''), $this->user);
        $this->client->request('POST', $url, ['password' => 'new-secret', 'password_confirm' => 'new-secret']);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $rows = $this->em->getRepository(UserLoginSession::class)->findBy(['user' => $this->user->getId()]);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertNotNull($row->getRevokedAt());
        }

        $remaining = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM rememberme_token WHERE username = :email', ['email' => 'user@example.com']);
        self::assertSame(0, (int) $remaining);
    }

    public function testPasswordResetSendIsRateLimitedPerIp(): void
    {
        $this->client->disableReboot();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $token = $this->setCsrfToken('password_reset_request');
            $this->client->request('POST', '/profile/password-reset', ['_token' => $token, 'email' => 'user@example.com']);
            self::assertResponseIsSuccessful();
        }

        $token = $this->setCsrfToken('password_reset_request');
        $this->client->request('POST', '/profile/password-reset', ['_token' => $token, 'email' => 'user@example.com']);

        self::assertResponseStatusCodeSame(429);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('Too many requests. Please try again in a few minutes.', $data['message']);
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
        $url = $this->signUrl('/profile/password-reset/submit/'.($this->user->getId()?->toRfc4122() ?? ''), $this->user);

        $this->client->request('POST', $url, ['password' => '']);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('password', $data['invalid']);
    }

    public function testPasswordResetSubmitRejectsTooShortPassword(): void
    {
        $url = $this->signUrl('/profile/password-reset/submit/'.($this->user->getId()?->toRfc4122() ?? ''), $this->user);

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
        $url = $this->signUrl('/profile/password-reset/submit/'.($this->user->getId()?->toRfc4122() ?? ''), $this->user);

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
        $url = $this->signUrl('/profile/password-reset/submit/'.($this->user->getId()?->toRfc4122() ?? ''), $this->user);

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

    /**
     * Signs a reset URL the way the application does: with the password
     * fingerprint of the given user included (when a user is passed).
     */
    private function signUrl(string $path, ?User $user = null): string
    {
        $signer = static::getContainer()->get(UriSigner::class);
        $url = 'http://localhost'.$path;

        if (null !== $user) {
            $userService = static::getContainer()->get(UserService::class);
            $url = $userService->withResetFingerprint($url, $user);
        }

        $signed = $signer->sign($url);

        return (string) (parse_url($signed, PHP_URL_PATH) ?? $path)
            .'?'.(parse_url($signed, PHP_URL_QUERY) ?? '');
    }

    private function seedLoginSession(User $user, string $token): void
    {
        $row = new UserLoginSession()
            ->setUser($user)
            ->setSessionToken(LoginSessionService::hashToken($token))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setLastSeenAt(new \DateTimeImmutable());
        $this->em->persist($row);
        $this->em->flush();
    }

    private function seedRememberMeToken(User $user): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO rememberme_token (series, value, lastUsed, class, username) VALUES (:series, :value, :lastUsed, :class, :username)',
            [
                'series' => bin2hex(random_bytes(16)),
                'value' => bin2hex(random_bytes(16)),
                'lastUsed' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'class' => User::class,
                'username' => (string) $user->getEmail(),
            ],
        );
    }

    /**
     * Attaches a confirmed recovery address directly (bypassing the double
     * opt-in) so no verification mail interferes with mailer assertions.
     *
     * @param non-empty-string $email
     */
    private function createRecoveryAddress(User $owner, string $email): RecoveryAddress
    {
        $address = new RecoveryAddress();
        $address->setEmail($email);
        $address->setVerifiedAt(new \DateTimeImmutable('-1 day'));
        $owner->addRecoveryAddress($address);
        $this->em->flush();
        $this->em->refresh($owner);

        return $address;
    }

    /**
     * Attaches an unverified recovery address with a pending verification
     * token and returns the address together with the plain token.
     *
     * @param non-empty-string $email
     *
     * @return array{RecoveryAddress, string}
     */
    private function createPendingRecoveryAddress(User $owner, string $email): array
    {
        $service = static::getContainer()->get(UserService::class);
        $address = new RecoveryAddress();
        $address->setEmail($email);
        $owner->addRecoveryAddress($address);
        $token = $service->issueVerification($address);
        $this->em->flush();
        $this->em->refresh($owner);

        return [$address, $token];
    }

    private function verifyPath(RecoveryAddress $address, string $token): string
    {
        return '/profile/recovery/verify/'.($address->getId()?->toRfc4122() ?? '').'/'.$token;
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

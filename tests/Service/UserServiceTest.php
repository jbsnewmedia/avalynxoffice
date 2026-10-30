<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Entity\RecoveryAddress;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\RecoveryAddressRepository;
use AvalynxOffice\Repository\UserRepository;
use AvalynxOffice\Service\UserService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserServiceTest extends TestCase
{
    #[Group('unit')]
    public function testFirstUserWithoutExistingAdminBecomesAdmin(): void
    {
        $service = $this->createService([]);

        $user = new User();
        $service->applyInitialRoles($user);

        // getRoles() always ensures ROLE_USER alongside the administrator role.
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    #[Group('unit')]
    public function testUserWithExistingAdminStaysRegularUser(): void
    {
        $service = $this->createService([new User()]);

        $user = new User();
        $service->applyInitialRoles($user);

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    #[Group('unit')]
    public function testLastActiveAdminIsDetected(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);

        $service = $this->createService([$admin]);

        self::assertTrue($service->isLastActiveAdmin($admin));
    }

    #[Group('unit')]
    public function testDeactivatedAdminIsNotTheLastActiveAdmin(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setActive(false);

        $service = $this->createService([]);

        self::assertFalse($service->isLastActiveAdmin($admin));
    }

    #[Group('unit')]
    public function testRegularUserIsNotTheLastActiveAdmin(): void
    {
        $user = new User();

        $service = $this->createService([$user]);

        self::assertFalse($service->isLastActiveAdmin($user));
    }

    #[Group('unit')]
    public function testToggleAdminGrantsRoleToRegularUser(): void
    {
        $user = new User();
        $service = $this->createService([$user]);

        $service->toggleAdmin($user);

        self::assertContains('ROLE_ADMIN', $user->getRoles());
    }

    #[Group('unit')]
    public function testToggleAdminRevokesRoleWhenOtherAdminsRemain(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $other = new User();
        $other->setRoles(['ROLE_ADMIN']);

        $service = $this->createService([$admin, $other]);

        $service->toggleAdmin($admin);

        self::assertNotContains('ROLE_ADMIN', $admin->getRoles());
    }

    #[Group('unit')]
    public function testToggleAdminRejectsRevocationForLastActiveAdmin(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);

        $service = $this->createService([$admin]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('The last active administrator cannot lose the administrator role.');
        $service->toggleAdmin($admin);
    }

    #[Group('unit')]
    public function testToggleAdminRevokesRoleForInactiveAdminWithoutOtherAdmins(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setActive(false);

        $service = $this->createService([]);

        $service->toggleAdmin($admin);

        self::assertNotContains('ROLE_ADMIN', $admin->getRoles());
    }

    private function createService(array $activeAdmins): UserService
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findActiveAdmins')->willReturn($activeAdmins);
        $recoveryAddressRepository = $this->createStub(RecoveryAddressRepository::class);
        $systemMailer = $this->createStub(MailerInterface::class);
        $uriSigner = $this->createStub(UriSigner::class);
        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);

        return new UserService($repository, $recoveryAddressRepository, $systemMailer, 'noreply@example.com', $uriSigner, $passwordHasher);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createRecoveryService(RecoveryAddressRepository $recoveryAddressRepository, MailerInterface $systemMailer, array $options = []): UserService
    {
        $repository = $this->createStub(UserRepository::class);
        if (array_key_exists('user', $options)) {
            $repository->method('findOneBy')->willReturn($options['user']);
        }
        $repository->method('findActiveAdmins')->willReturn([]);

        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturnCallback(
            static fn (User $user, string $password): string => 'hashed:'.$password,
        );

        return new UserService(
            $repository,
            $recoveryAddressRepository,
            $systemMailer,
            'noreply@example.com',
            new UriSigner('unit-test-secret'),
            $passwordHasher,
        );
    }

    private function createRecoveryRepository(array $byEmail = []): RecoveryAddressRepository
    {
        $repository = $this->createStub(RecoveryAddressRepository::class);
        $repository->method('findByEmail')->willReturn($byEmail);

        return $repository;
    }

    /**
     * @return \Closure(RecoveryAddress, string): string
     */
    private static function verifyUrlFor(): \Closure
    {
        return static fn (RecoveryAddress $address, string $token): string => 'https://office.example/profile/recovery/verify/'.($address->getId()?->toRfc4122() ?? '').'/'.$token;
    }

    private static function createOwner(): User
    {
        $user = new User();
        $user->setEmail('owner@example.com');

        return $user;
    }

    /**
     * Attaches an already verified recovery address directly (bypassing the
     * double opt-in) so tests of the recovery flows do not trigger the
     * verification mail.
     *
     * @param non-empty-string $email
     */
    private static function attachVerifiedAddress(User $user, string $email): RecoveryAddress
    {
        $address = new RecoveryAddress();
        $address->setEmail($email);
        $address->setVerifiedAt(new \DateTimeImmutable());
        $user->addRecoveryAddress($address);

        return $address;
    }

    #[Group('unit')]
    public function testAddRecoveryAddressRegistersLowercasedAddress(): void
    {
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $this->createStub(MailerInterface::class));
        $user = self::createOwner();

        $address = $service->addRecoveryAddress($user, 'Recovery@Example.COM', self::verifyUrlFor());

        self::assertSame('recovery@example.com', $address->getEmail());
        self::assertSame($user, $address->getUser());
        self::assertCount(1, $user->getRecoveryAddresses());
    }

    #[Group('unit')]
    public function testAddRecoveryAddressStartsUnverifiedAndSendsVerificationMail(): void
    {
        $sent = [];
        $systemMailer = $this->createMock(MailerInterface::class);
        $systemMailer->expects($this->once())->method('send')->willReturnCallback(
            static function (\Symfony\Component\Mime\RawMessage $message) use (&$sent): void {
                $sent[] = $message;
            },
        );
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $systemMailer);
        $user = new User();
        $user->setEmail('owner@example.com');

        $address = $service->addRecoveryAddress($user, 'recovery@example.com', self::verifyUrlFor());

        self::assertFalse($address->isVerified());
        self::assertNull($address->getVerifiedAt());
        self::assertSame([], $user->getVerifiedRecoveryAddresses());
        $hash = $address->getVerificationTokenHash();
        self::assertIsString($hash);
        self::assertSame(64, strlen($hash));
        $expiresAt = $address->getVerificationExpiresAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $expiresAt);
        self::assertGreaterThan(new \DateTimeImmutable('+23 hours'), $expiresAt);
        self::assertLessThan(new \DateTimeImmutable('+25 hours'), $expiresAt);

        self::assertCount(1, $sent);
        $message = $sent[0];
        self::assertInstanceOf(Email::class, $message);
        self::assertSame(['recovery@example.com'], array_map(
            static fn (Address $recipient): string => $recipient->getAddress(),
            $message->getTo(),
        ));
        $body = (string) $message->getTextBody();
        self::assertStringContainsString('owner@example.com', $body);
        preg_match('#https://office\.example/profile/recovery/verify/[^/\s]+/([^\s]+)#', $body, $matches);
        self::assertNotEmpty($matches, $body);

        // The mail carries the plain token, the entity only its SHA-256 hash.
        $token = $matches[1];
        self::assertGreaterThanOrEqual(43, strlen($token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
        self::assertStringNotContainsString($token, $hash);
        self::assertSame(hash('sha256', $token), $hash);
    }

    #[Group('unit')]
    public function testVerifyRecoveryAddressAcceptsValidTokenOnce(): void
    {
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $this->createStub(MailerInterface::class));
        $address = new RecoveryAddress();
        $address->setEmail('recovery@example.com');
        $token = $service->issueVerification($address);

        self::assertTrue($service->verifyRecoveryAddress($address, $token));
        self::assertTrue($address->isVerified());
        self::assertNull($address->getVerificationTokenHash());
        self::assertNull($address->getVerificationExpiresAt());

        // A second use of the same link is rejected.
        self::assertFalse($service->verifyRecoveryAddress($address, $token));
    }

    #[Group('unit')]
    public function testVerifyRecoveryAddressRejectsWrongToken(): void
    {
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $this->createStub(MailerInterface::class));
        $address = new RecoveryAddress();
        $address->setEmail('recovery@example.com');
        $token = $service->issueVerification($address);

        self::assertFalse($service->verifyRecoveryAddress($address, ''));
        self::assertFalse($service->verifyRecoveryAddress($address, 'wrong-token'));
        self::assertFalse($service->verifyRecoveryAddress($address, $token.'x'));
        self::assertFalse($address->isVerified());
        self::assertNotNull($address->getVerificationTokenHash());
    }

    #[Group('unit')]
    public function testVerifyRecoveryAddressRejectsExpiredToken(): void
    {
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $this->createStub(MailerInterface::class));
        $address = new RecoveryAddress();
        $address->setEmail('recovery@example.com');
        $token = $service->issueVerification($address);
        $address->setVerificationExpiresAt(new \DateTimeImmutable('-1 second'));

        self::assertFalse($service->verifyRecoveryAddress($address, $token));
        self::assertFalse($address->isVerified());
    }

    #[Group('unit')]
    public function testVerifyRecoveryAddressRejectsWithoutPendingToken(): void
    {
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $this->createStub(MailerInterface::class));
        $address = new RecoveryAddress();
        $address->setEmail('recovery@example.com');

        self::assertFalse($service->verifyRecoveryAddress($address, 'anything'));
    }

    #[Group('unit')]
    public function testSendVerificationMailSupersedesPendingTokenAndRejectsVerifiedAddress(): void
    {
        $systemMailer = $this->createMock(MailerInterface::class);
        $systemMailer->expects($this->once())->method('send');
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $systemMailer);
        $address = new RecoveryAddress();
        $address->setEmail('recovery@example.com');
        $firstToken = $service->issueVerification($address);

        $service->sendVerificationMail($address, self::verifyUrlFor());

        self::assertFalse($service->verifyRecoveryAddress($address, $firstToken));

        $address->setVerifiedAt(new \DateTimeImmutable());
        $this->expectException(\DomainException::class);
        $service->sendVerificationMail($address, self::verifyUrlFor());
    }

    #[Group('unit')]
    public function testAddRecoveryAddressRejectsSecondAddressWhenLimitReached(): void
    {
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $this->createStub(MailerInterface::class));
        $user = self::createOwner();
        $service->addRecoveryAddress($user, 'first@example.com', self::verifyUrlFor());
        $service->addRecoveryAddress($user, 'second@example.com', self::verifyUrlFor());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('A user may register at most 2 recovery addresses.');
        $service->addRecoveryAddress($user, 'third@example.com', self::verifyUrlFor());
    }

    #[Group('unit')]
    public function testAddRecoveryAddressRejectsDuplicateForSameUser(): void
    {
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $this->createStub(MailerInterface::class));
        $user = self::createOwner();
        $service->addRecoveryAddress($user, 'first@example.com', self::verifyUrlFor());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('This recovery address is already registered for the user.');
        $service->addRecoveryAddress($user, 'FIRST@example.com', self::verifyUrlFor());
    }

    /**
     * The message must not reveal that the address belongs to another
     * account (enumeration).
     */
    #[Group('unit')]
    public function testAddRecoveryAddressRejectsAddressUsedByOtherUserWithNeutralMessage(): void
    {
        $other = new RecoveryAddress();
        $other->setEmail('taken@example.com');
        $service = $this->createRecoveryService($this->createRecoveryRepository([$other]), $this->createStub(MailerInterface::class));

        try {
            $service->addRecoveryAddress(self::createOwner(), 'taken@example.com', self::verifyUrlFor());
            self::fail('Expected a DomainException.');
        } catch (\DomainException $exception) {
            self::assertSame('This recovery address cannot be registered.', $exception->getMessage());
            self::assertStringNotContainsString('another user', $exception->getMessage());
        }
    }

    #[Group('unit')]
    public function testRemoveRecoveryAddressDetachesFromUser(): void
    {
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $this->createStub(MailerInterface::class));
        $user = self::createOwner();
        $address = $service->addRecoveryAddress($user, 'first@example.com', self::verifyUrlFor());

        $service->removeRecoveryAddress($user, $address);

        self::assertCount(0, $user->getRecoveryAddresses());
        self::assertNull($address->getUser());
    }

    #[Group('unit')]
    public function testSendToRecoveryAddressesSkipsWithoutAddresses(): void
    {
        $systemMailer = $this->createMock(MailerInterface::class);
        $systemMailer->expects($this->never())->method('send');
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $systemMailer);

        $service->sendToRecoveryAddresses(new User(), 'Subject', 'Body');
    }

    #[Group('unit')]
    public function testSendToRecoveryAddressesSkipsUnverifiedAddresses(): void
    {
        $sent = [];
        $systemMailer = $this->createMock(MailerInterface::class);
        $systemMailer->method('send')->willReturnCallback(
            static function (\Symfony\Component\Mime\RawMessage $message) use (&$sent): void {
                $sent[] = $message;
            },
        );
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $systemMailer);
        $user = self::createOwner();
        self::attachVerifiedAddress($user, 'verified@example.com');
        $service->addRecoveryAddress($user, 'pending@example.com', self::verifyUrlFor());
        $sent = [];

        $service->sendToRecoveryAddresses($user, 'Subject', 'Body');

        self::assertCount(1, $sent);
        $message = $sent[0];
        self::assertInstanceOf(Email::class, $message);
        self::assertSame(['verified@example.com'], array_map(
            static fn (Address $recipient): string => $recipient->getAddress(),
            $message->getTo(),
        ));
    }

    #[Group('unit')]
    public function testSendToRecoveryAddressesSendsOneMailToAllAddresses(): void
    {
        $sent = [];
        $systemMailer = $this->createMock(MailerInterface::class);
        $systemMailer->expects($this->once())->method('send')->willReturnCallback(
            static function (\Symfony\Component\Mime\RawMessage $message) use (&$sent): void {
                $sent[] = $message;
            },
        );
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $systemMailer);
        $user = new User();
        self::attachVerifiedAddress($user, 'one@example.com');
        self::attachVerifiedAddress($user, 'two@example.com');

        $service->sendToRecoveryAddresses($user, 'Subject', 'Body');

        self::assertCount(1, $sent);
        $message = $sent[0];
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('Subject', $message->getSubject());
        self::assertSame('Body', $message->getTextBody());
        self::assertSame(['one@example.com', 'two@example.com'], array_map(
            static fn (Address $recipient): string => $recipient->getAddress(),
            $message->getTo(),
        ));
    }

    #[Group('unit')]
    public function testRequestPasswordResetDoesNothingForUnknownUser(): void
    {
        $systemMailer = $this->createMock(MailerInterface::class);
        $systemMailer->expects($this->never())->method('send');
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $systemMailer);

        $service->requestPasswordReset('unknown@example.com', static fn (User $candidate): string => 'https://office.example/reset/'.($candidate->getId()?->toRfc4122() ?? ''));
    }

    #[Group('unit')]
    public function testRequestPasswordResetDoesNothingWithoutAddresses(): void
    {
        $systemMailer = $this->createMock(MailerInterface::class);
        $systemMailer->expects($this->never())->method('send');
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $systemMailer, ['user' => new User()]);

        $service->requestPasswordReset('known@example.com', static fn (User $candidate): string => 'https://office.example/reset/'.($candidate->getId()?->toRfc4122() ?? ''));
    }

    /**
     * An address whose ownership was never confirmed must not receive
     * reset links - otherwise anybody could register a foreign mailbox and
     * route the victim's reset mail there.
     */
    #[Group('unit')]
    public function testRequestPasswordResetIgnoresUnverifiedAddresses(): void
    {
        $sent = [];
        $systemMailer = $this->createMock(MailerInterface::class);
        $systemMailer->method('send')->willReturnCallback(
            static function (\Symfony\Component\Mime\RawMessage $message) use (&$sent): void {
                $sent[] = $message;
            },
        );
        $user = new User();
        $user->setEmail('known@example.com');
        $user->setPassword('current-hash');
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $systemMailer, ['user' => $user]);
        $service->addRecoveryAddress($user, 'pending@example.com', self::verifyUrlFor());
        self::assertCount(1, $sent, 'only the verification mail was sent');
        $sent = [];

        $service->requestPasswordReset('known@example.com', static fn (User $candidate): string => 'https://office.example/reset/'.($candidate->getId()?->toRfc4122() ?? ''));

        self::assertSame([], $sent);
    }

    #[Group('unit')]
    public function testRequestPasswordResetSendsSignedLinkToAllAddresses(): void
    {
        $sent = [];
        $systemMailer = $this->createMock(MailerInterface::class);
        $systemMailer->expects($this->once())->method('send')->willReturnCallback(
            static function (\Symfony\Component\Mime\RawMessage $message) use (&$sent): void {
                $sent[] = $message;
            },
        );
        $user = new User();
        $user->setEmail('known@example.com');
        $user->setPassword('current-hash');
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $systemMailer, ['user' => $user]);
        self::attachVerifiedAddress($user, 'one@example.com');

        $service->requestPasswordReset('KNOWN@example.com', static fn (User $candidate): string => 'https://office.example/profile/password-reset/execute/'.($candidate->getId()?->toRfc4122() ?? ''));

        self::assertCount(1, $sent);
        $message = $sent[0];
        self::assertInstanceOf(Email::class, $message);
        $body = (string) $message->getTextBody();
        self::assertSame('Password Reset Request', $message->getSubject());
        self::assertMatchesRegularExpression('/https:\/\/office\.example\/[^\s]+/', $body, $body);
        preg_match('/https:\/\/office\.example\/[^\s]+/', $body, $matches);
        self::assertNotEmpty($matches);

        // The link carries the password fingerprint and is validly signed.
        $link = $matches[0];
        self::assertStringContainsString(UserService::RESET_FINGERPRINT_PARAMETER.'='.$service->passwordResetFingerprint($user), $link);
        self::assertTrue(new UriSigner('unit-test-secret')->check($link));
    }

    #[Group('unit')]
    public function testPasswordResetFingerprintChangesWithThePassword(): void
    {
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $this->createStub(MailerInterface::class));
        $user = new User();
        $user->setPassword('old-hash');

        $before = $service->passwordResetFingerprint($user);
        self::assertSame(16, strlen($before));
        self::assertTrue($service->isResetFingerprintValid($user, $before));
        self::assertFalse($service->isResetFingerprintValid($user, null));
        self::assertFalse($service->isResetFingerprintValid($user, 'wrong'));

        $service->resetPassword($user, 'new-secret');

        self::assertNotSame($before, $service->passwordResetFingerprint($user));
        self::assertFalse($service->isResetFingerprintValid($user, $before));
    }

    #[Group('unit')]
    public function testWithResetFingerprintAppendsQueryParameter(): void
    {
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $this->createStub(MailerInterface::class));
        $user = new User();
        $user->setPassword('hash');
        $fingerprint = $service->passwordResetFingerprint($user);

        self::assertSame('https://office.example/reset?v='.$fingerprint, $service->withResetFingerprint('https://office.example/reset', $user));
        self::assertSame('https://office.example/reset?a=1&v='.$fingerprint, $service->withResetFingerprint('https://office.example/reset?a=1', $user));
    }

    #[Group('unit')]
    public function testResetPasswordHashesAndStoresPassword(): void
    {
        $service = $this->createRecoveryService($this->createRecoveryRepository(), $this->createStub(MailerInterface::class));
        $user = new User();
        $user->setPassword('old-hash');

        $service->resetPassword($user, 'new-secret');

        self::assertSame('hashed:new-secret', $user->getPassword());
    }
}

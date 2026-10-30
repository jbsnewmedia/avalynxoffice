<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\RecoveryAddress;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\RecoveryAddressRepository;
use AvalynxOffice\Repository\UserRepository;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Central user/account helpers: automatic administrator onboarding and
 * protection of the last active administrator account.
 */
final readonly class UserService
{
    /**
     * Maximum number of recovery addresses a user may register. Each address
     * is used exclusively for account recovery messages; both registered
     * addresses always receive the recovery mail simultaneously.
     */
    public const int MAX_RECOVERY_ADDRESSES = 2;

    /**
     * Query parameter of the signed reset link carrying the password
     * fingerprint. The signature covers it, so the link stops working as
     * soon as the password (and with it the fingerprint) changes.
     */
    public const string RESET_FINGERPRINT_PARAMETER = 'v';

    /** Number of hex characters of the fingerprint included in the link. */
    private const int RESET_FINGERPRINT_LENGTH = 16;

    /** Validity of a signed reset link (ISO 8601 duration: 30 minutes). */
    public const string RESET_LINK_TTL = 'PT30M';

    public function __construct(
        private UserRepository $userRepository,
        private RecoveryAddressRepository $recoveryAddressRepository,
        private MailerInterface $systemMailer,
        private string $systemMailerFromAddress,
        private UriSigner $uriSigner,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Assigns the roles for a newly created user. As long as the
     * installation has no active administrator, the first user becomes the
     * administrator automatically; everybody else starts as a regular user.
     */
    public function applyInitialRoles(User $user): void
    {
        $user->setRoles(
            [] === $this->userRepository->findActiveAdmins() ? ['ROLE_ADMIN'] : ['ROLE_USER'],
        );
    }

    /**
     * True when the given user is the only active administrator. Deactivating
     * this account or revoking its role would leave the installation without
     * any administrator and must be prevented.
     */
    public function isLastActiveAdmin(User $user): bool
    {
        if (!$user->isActive() || !in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return false;
        }

        return 1 >= count($this->userRepository->findActiveAdmins());
    }

    /**
     * Grants or revokes the administrator role. Revoking is rejected for the
     * last active administrator.
     *
     * @throws \DomainException when the last active administrator would lose the role
     */
    public function toggleAdmin(User $user): void
    {
        $roles = $user->getRoles();
        $isAdmin = in_array('ROLE_ADMIN', $roles, true);

        if ($isAdmin) {
            if ($this->isLastActiveAdmin($user)) {
                throw new \DomainException('The last active administrator cannot lose the administrator role.');
            }

            $roles = array_values(array_diff($roles, ['ROLE_ADMIN']));
        } else {
            $roles[] = 'ROLE_ADMIN';
        }

        $user->setRoles($roles);
    }

    /**
     * Registers a recovery-only email address for the given user. Recovery
     * addresses never take part in mail retrieval or sending; they are used
     * exclusively to receive account-recovery messages. At most
     * {@see self::MAX_RECOVERY_ADDRESSES} addresses may be registered.
     *
     * @param non-empty-string $email
     *
     * @throws \DomainException when the limit is reached or the address already exists
     */
    public function addRecoveryAddress(User $user, string $email): RecoveryAddress
    {
        $normalized = strtolower($email);

        if (count($user->getRecoveryAddresses()) >= self::MAX_RECOVERY_ADDRESSES) {
            throw new \DomainException(sprintf('A user may register at most %d recovery addresses.', self::MAX_RECOVERY_ADDRESSES));
        }

        foreach ($user->getRecoveryAddresses() as $existing) {
            if ($existing->getEmail() === $normalized) {
                throw new \DomainException('This recovery address is already registered for the user.');
            }
        }

        // Neutral wording: the message must not reveal that the address
        // belongs to another account (enumeration).
        if ([] !== $this->recoveryAddressRepository->findByEmail($normalized)) {
            throw new \DomainException('This recovery address cannot be registered.');
        }

        $address = new RecoveryAddress();
        $address->setEmail($normalized);
        $user->addRecoveryAddress($address);

        return $address;
    }

    /**
     * Removes a recovery address from the user account.
     */
    public function removeRecoveryAddress(User $user, RecoveryAddress $recoveryAddress): void
    {
        $user->removeRecoveryAddress($recoveryAddress);
    }

    /**
     * Sends the given message to every registered recovery address of the
     * user simultaneously. All addresses are attached to one mail as
     * explicit recipients, so they receive the identical message together.
     *
     * @param non-empty-string $subject
     * @param non-empty-string $body
     */
    public function sendToRecoveryAddresses(User $user, string $subject, string $body): void
    {
        $recipients = [];
        foreach ($user->getRecoveryAddresses() as $recoveryAddress) {
            $recipients[] = new Address($recoveryAddress->getEmail());
        }

        if ([] === $recipients) {
            return;
        }

        $message = new Email()
            ->from($this->systemMailerFromAddress)
            ->to(...$recipients)
            ->subject($subject)
            ->text($body);

        $this->systemMailer->send($message);
    }

    /**
     * Generates a signed password reset link and sends it to the user's
     * recovery addresses. Unknown users and users without recovery
     * addresses are silently ignored to prevent account enumeration.
     *
     * The link is bound to the current password via
     * {@see self::passwordResetFingerprint()}: once the password changed the
     * signature no longer matches and the link cannot be used a second time.
     *
     * @param non-empty-string       $email
     * @param \Closure(User): string $resetUrlFor builds the absolute reset URL for the given user
     */
    public function requestPasswordReset(string $email, \Closure $resetUrlFor): void
    {
        $user = $this->userRepository->findOneBy(['email' => strtolower($email)]);

        if (null === $user || [] === $user->getRecoveryAddresses()->toArray()) {
            return;
        }

        // UriSigner interprets an int expiration as an absolute timestamp -
        // the validity therefore has to be passed as interval.
        $signedUrl = $this->uriSigner->sign($this->withResetFingerprint($resetUrlFor($user), $user), new \DateInterval(self::RESET_LINK_TTL));

        $this->sendToRecoveryAddresses(
            $user,
            'Password Reset Request',
            sprintf('Hello %s,'."\n\n".'a password reset was requested for your account. Please use the following link to reset your password:'."\n\n".'%s'."\n\n".'This link is valid for 30 minutes.', $user->getEmail(), $signedUrl)
        );
    }

    /**
     * Fingerprint of the user's current password hash (first 16 hex
     * characters of its SHA-256). It changes with every password change and
     * reveals nothing about the hash itself.
     */
    public function passwordResetFingerprint(User $user): string
    {
        return substr(hash('sha256', $user->getPassword()), 0, self::RESET_FINGERPRINT_LENGTH);
    }

    /**
     * Appends the password fingerprint to a reset URL before signing.
     */
    public function withResetFingerprint(string $url, User $user): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').self::RESET_FINGERPRINT_PARAMETER.'='.$this->passwordResetFingerprint($user);
    }

    /**
     * True when the fingerprint carried by a reset link still matches the
     * user's current password.
     */
    public function isResetFingerprintValid(User $user, ?string $fingerprint): bool
    {
        return is_string($fingerprint) && hash_equals($this->passwordResetFingerprint($user), $fingerprint);
    }

    /**
     * Resets the password of the given user after verifying the signed reset link.
     */
    public function resetPassword(User $user, string $newPassword): void
    {
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);
    }
}

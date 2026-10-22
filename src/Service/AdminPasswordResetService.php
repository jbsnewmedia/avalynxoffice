<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\PasswordResetRequest;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\PasswordResetRequestRepository;
use AvalynxOffice\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Two-key password reset handshake for users without mailbox access:
 * - The user starts the flow, receives a token AND the 4-digit release number.
 * - The user hands both to the administrator in person.
 * - The administrator confirms the number (max 3 attempts) and releases the flow.
 * - The user sets the new password within the release window.
 *
 * Neither party can complete the flow alone: the administrator cannot release
 * without the number spoken by the user, and the user cannot set the password
 * without the release. Both secrets are stored hashed; plaintext is shown
 * exactly once at generation time.
 */
final readonly class AdminPasswordResetService
{
    /** Alphabet without ambiguous characters (no O/0, I/1, L). */
    private const string TOKEN_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Length of the generated token. */
    private const int TOKEN_LENGTH = 12;

    /** Format of the token shown to the user: XXXX-XXXX-XXXX. */
    private const int TOKEN_GROUP_LENGTH = 4;

    /** Lower bound (inclusive) of the 4-digit release number. */
    private const int NUMBER_MIN = 1000;

    /** Upper bound (inclusive) of the 4-digit release number. */
    private const int NUMBER_MAX = 9999;

    public function __construct(
        private PasswordResetRequestRepository $passwordResetRequestRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Starts a reset request for the user with the given email address.
     * Creates a new request and supersedes all open requests of the same
     * user so that exactly one flow is active.
     *
     * @param non-empty-string $email
     * @param non-empty-string $adminId    ID of the requesting administrator
     * @param non-empty-string $adminIp    IP of the requesting administrator
     * @param int              $claimTtl   seconds the user has to show the token
     * @param int              $releaseTtl seconds the user has to set the password after release
     *
     * @return array{request: PasswordResetRequest, token: string, number: int}
     *
     * @throws \DomainException when the email does not belong to an active user
     */
    public function startRequest(
        string $email,
        string $adminId,
        string $adminIp,
        int $claimTtl = PasswordResetRequest::CLAIM_TTL_SECONDS,
        int $releaseTtl = PasswordResetRequest::RELEASE_TTL_SECONDS,
    ): array {
        $user = $this->userRepository->findOneBy(['email' => strtolower($email)]);

        if (null === $user || !$user->isActive()) {
            throw new \DomainException('No active user with this email address.');
        }

        $result = $this->createRequest($user, $adminIp, $claimTtl);

        $result['request']->setCreatedByAdmin(self::uuidFrom($adminId));
        $this->entityManager->flush();

        return $result;
    }

    /**
     * Starts a reset request for the given user themselves: the user generates
     * token and release number on the reset page and presents both to the
     * administrator in person. As the user sees the token from the start, the
     * request is created in the claimed state and can be released right away.
     *
     * @param non-empty-string $ip IP of the requesting user
     *
     * @return array{request: PasswordResetRequest, token: string, number: int}
     */
    public function startUserRequest(
        User $user,
        string $ip,
        int $claimTtl = PasswordResetRequest::CLAIM_TTL_SECONDS,
        int $releaseTtl = PasswordResetRequest::RELEASE_TTL_SECONDS,
    ): array {
        $result = $this->createRequest($user, $ip, $claimTtl);

        $token = $result['token'];
        \assert('' !== $token);
        $claimed = $this->claim($token);

        return ['request' => $claimed, 'token' => $token, 'number' => $result['number']];
    }

    /**
     * Creates a new pending request for the user and supersedes all open
     * requests of the same user so that exactly one flow is active.
     *
     * @return array{request: PasswordResetRequest, token: string, number: int}
     */
    private function createRequest(User $user, string $ip, int $claimTtl): array
    {
        $this->invalidateOpenRequests($user);

        $token = $this->generateToken();
        $number = random_int(self::NUMBER_MIN, self::NUMBER_MAX);
        $now = new \DateTimeImmutable();

        $request = new PasswordResetRequest();
        $request->setUser($user);
        $request->setTokenHash(hash('sha256', $token));
        $request->setReleaseNumberHash($this->hashNumber($number));
        $request->setStatus(PasswordResetRequest::STATUS_PENDING);
        $request->setExpiresAt($now->modify(sprintf('+%d seconds', $claimTtl)));
        $request->setCreatedIp($ip);

        $this->entityManager->persist($request);
        $this->entityManager->flush();

        return ['request' => $request, 'token' => $token, 'number' => $number];
    }

    /**
     * The user presents the token; the request moves from pending to claimed.
     * Expired requests are marked as expired and rejected.
     *
     * @param non-empty-string $token
     *
     * @throws \DomainException when the token is unknown or the request expired
     */
    public function claim(string $token, \DateTimeImmutable $now = new \DateTimeImmutable()): PasswordResetRequest
    {
        $request = $this->passwordResetRequestRepository->findByToken($token);

        if (null === $request) {
            throw new \DomainException('Unknown password reset token.');
        }

        if (PasswordResetRequest::STATUS_PENDING !== $request->getStatus()) {
            throw new \DomainException('This reset request is not waiting to be claimed.');
        }

        $expiresAt = $request->getExpiresAt();

        if (null === $expiresAt || $expiresAt <= $now) {
            $request->setStatus(PasswordResetRequest::STATUS_EXPIRED);
            $this->entityManager->flush();

            throw new \DomainException('This reset request has expired.');
        }

        $request->setStatus(PasswordResetRequest::STATUS_CLAIMED);
        $this->entityManager->flush();

        return $request;
    }

    /**
     * The administrator releases the request by confirming the number the
     * user spoke. At most {@see PasswordResetRequest::MAX_NUMBER_ATTEMPTS}
     * attempts are allowed; after that the request is invalidated.
     *
     * @param non-empty-string $adminId ID of the releasing administrator
     * @param non-empty-string $adminIp IP of the releasing administrator
     *
     * @throws \DomainException when the state, the deadline or the number is wrong
     */
    public function release(PasswordResetRequest $request, int $number, string $adminId, string $adminIp, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        if (PasswordResetRequest::STATUS_CLAIMED !== $request->getStatus()) {
            throw new \DomainException('This reset request is not waiting for a release.');
        }

        $releaseExpiresAt = $request->getReleaseExpiresAt();
        $expiresAt = $request->getExpiresAt();

        if (null === $releaseExpiresAt) {
            // First release attempt: start the release window now. The claim
            // deadline must still be in the future for the release window to
            // end before the request could be restarted by a newer request.
            $releaseExpiresAt = $now->modify(sprintf('+%d seconds', PasswordResetRequest::RELEASE_TTL_SECONDS));
            $request->setReleaseExpiresAt($releaseExpiresAt);
        } elseif ($releaseExpiresAt <= $now) {
            $request->setStatus(PasswordResetRequest::STATUS_EXPIRED);
            $this->entityManager->flush();

            throw new \DomainException('The release window of this reset request has expired.');
        }

        if (null !== $expiresAt && $expiresAt <= $now) {
            $request->setStatus(PasswordResetRequest::STATUS_EXPIRED);
            $this->entityManager->flush();

            throw new \DomainException('This reset request has expired.');
        }

        if (!hash_equals($request->getReleaseNumberHash(), $this->hashNumber($number))) {
            $request->incrementAttempts();

            if ($request->getAttempts() >= PasswordResetRequest::MAX_NUMBER_ATTEMPTS) {
                $request->setStatus(PasswordResetRequest::STATUS_INVALIDATED);
            }

            $this->entityManager->flush();

            throw new \DomainException('Incorrect release number.');
        }

        $request->setStatus(PasswordResetRequest::STATUS_RELEASED);
        $request->setReleasedByAdmin(self::uuidFrom($adminId));
        $request->setReleasedIp($adminIp);
        $this->entityManager->flush();
    }

    /**
     * Completes the flow: stores the new password and marks the request as
     * completed. The release window must still be open.
     *
     * @param non-empty-string $newPassword
     *
     * @throws \DomainException when the request was not released or the window expired
     */
    public function complete(PasswordResetRequest $request, string $newPassword, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        if (PasswordResetRequest::STATUS_RELEASED !== $request->getStatus()) {
            throw new \DomainException('This reset request has not been released.');
        }

        $releaseExpiresAt = $request->getReleaseExpiresAt();

        if (null === $releaseExpiresAt || $releaseExpiresAt <= $now) {
            $request->setStatus(PasswordResetRequest::STATUS_EXPIRED);
            $this->entityManager->flush();

            throw new \DomainException('The release window of this reset request has expired.');
        }

        $user = $request->getUser();

        if (null === $user) {
            throw new \DomainException('The user of this reset request no longer exists.');
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $request->setStatus(PasswordResetRequest::STATUS_COMPLETED);
        $request->setCompletedAt($now);
        $this->entityManager->flush();
    }

    /**
     * Marks every open request of the user (any other state) as invalidated so
     * that exactly one flow can be active at a time.
     */
    public function invalidateOpenRequests(User $user): void
    {
        foreach ($this->passwordResetRequestRepository->findActiveByUser($user) as $open) {
            $open->setStatus(PasswordResetRequest::STATUS_INVALIDATED);
        }
    }

    /**
     * Returns all requests that are still open from the administrator's point
     * of view: pending, claimed or released.
     *
     * @return list<PasswordResetRequest>
     */
    public function listOpenRequests(): array
    {
        return $this->passwordResetRequestRepository->findOpenRequests();
    }

    /**
     * Finds a request by its identifier (UUID string representation).
     */
    public function findRequestById(string $id): ?PasswordResetRequest
    {
        $result = $this->passwordResetRequestRepository->createQueryBuilder('r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getResult();
        \assert(is_array($result));

        foreach ($result as $request) {
            if ($request instanceof PasswordResetRequest) {
                return $request;
            }
        }

        return null;
    }

    /**
     * True when the request is pending, claimed or released and the claim
     * deadline has not passed. Used by the user-facing page to decide which
     * state of the flow to render.
     */
    public function isAwaitingUser(PasswordResetRequest $request, \DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        if (in_array($request->getStatus(), [
            PasswordResetRequest::STATUS_PENDING,
            PasswordResetRequest::STATUS_CLAIMED,
            PasswordResetRequest::STATUS_RELEASED,
        ], true)) {
            $expiresAt = $request->getExpiresAt();

            return null !== $expiresAt && $expiresAt > $now;
        }

        return false;
    }

    /**
     * @return non-empty-string
     */
    private function generateToken(): string
    {
        $alphabetLength = strlen(self::TOKEN_ALPHABET);
        $characters = [];

        for ($index = 0; $index < self::TOKEN_LENGTH; ++$index) {
            $characters[] = self::TOKEN_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        $groups = array_map(
            static fn (array $group): string => implode('', $group),
            array_chunk($characters, self::TOKEN_GROUP_LENGTH),
        );

        $token = implode('-', $groups);
        \assert('' !== $token);

        return $token;
    }

    /**
     * Numbers are hashed with a fixed context string so that a leaked database
     * dump does not directly expose the 90-9999 plaintext values.
     */
    private function hashNumber(int $number): string
    {
        return hash('sha256', 'password-reset-release:'.$number);
    }

    private static function uuidFrom(string $id): \Symfony\Component\Uid\UuidV7
    {
        return \Symfony\Component\Uid\UuidV7::fromRfc4122($id);
    }
}

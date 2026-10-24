<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Login;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Entity\UserLoginSession;
use AvalynxOffice\EventSubscriber\LoginRememberMeSubscriber;
use AvalynxOffice\Repository\UserLoginSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Registry for concurrent logins: every interactive login creates a row,
 * every request refreshes its activity and rows can be revoked (kicked)
 * from the profile. The row token is stored inside the Symfony session —
 * a missing or revoked token means the session must be terminated.
 */
final readonly class LoginSessionService
{
    /** Session attribute carrying this login's registry token. */
    public const string SESSION_ATTRIBUTE = 'login_session_token';

    /** Session attribute set when the session must be terminated. */
    public const string FORCE_LOGOUT_ATTRIBUTE = 'login_session_force_logout';

    /** Refresh throttling for last_seen_at updates (seconds). */
    private const int TOUCH_INTERVAL = 60;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GeoIpLookupService $geoIpLookup,
    ) {
    }

    /**
     * Creates (or refreshes) the login session row for the request session.
     * Called on every interactive login.
     */
    public function register(User $user, Request $request): void
    {
        $session = $request->getSession();
        $rememberMe = (bool) $session->get(LoginRememberMeSubscriber::SESSION_KEY, false);
        $token = $session->get(self::SESSION_ATTRIBUTE);

        if (is_string($token) && '' !== $token) {
            $row = $this->repository()->findOneBy(['sessionToken' => $token, 'user' => $user]);

            if (null !== $row && null === $row->getRevokedAt()) {
                $this->refresh($row, $request, $rememberMe);

                return;
            }
        }

        $token = bin2hex(random_bytes(16));
        $session->set(self::SESSION_ATTRIBUTE, $token);

        $row = new UserLoginSession()
            ->setUser($user)
            ->setSessionToken($token)
            ->setRememberMe($rememberMe)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setLastSeenAt(new \DateTimeImmutable());

        $this->applyRequestData($row, $request);
        $this->entityManager->persist($row);
        $this->entityManager->flush();
    }

    /**
     * On every request: refresh the activity of the session row, or kill
     * the session when its row is missing or was revoked (kicked).
     */
    public function handleRequest(User $user, Request $request): void
    {
        $session = $request->getSession();
        $token = $session->get(self::SESSION_ATTRIBUTE);

        if (!is_string($token) || '' === $token) {
            return;
        }

        $row = $this->repository()->findActiveByToken($user, $token);

        if (null === $row) {
            $this->terminate($request, $session);

            return;
        }

        $lastSeen = $row->getLastSeenAt();

        if (null === $lastSeen || new \DateTimeImmutable()->getTimestamp() - $lastSeen->getTimestamp() >= self::TOUCH_INTERVAL) {
            $row->setLastSeenAt(new \DateTimeImmutable());
            $this->applyRequestData($row, $request);
            $this->entityManager->flush();
        }
    }

    /**
     * Ends a single login session of the user. Returns true when a session
     * was revoked.
     */
    public function revoke(User $user, string $tokenId): bool
    {
        $row = $this->entityManager->find(UserLoginSession::class, $tokenId);

        if (!$row instanceof UserLoginSession || $row->getUser()?->getId()?->toRfc4122() !== $user->getId()?->toRfc4122() || null !== $row->getRevokedAt()) {
            return false;
        }

        $row->setRevokedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->revokeRememberTokens($user);

        return true;
    }

    /**
     * Deletes all remember-me tokens of the user so a kicked session is not
     * silently restored by the remember-me cookie auto-login.
     */
    private function revokeRememberTokens(User $user): void
    {
        $affected = $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM rememberme_token WHERE username = :email',
            ['email' => $user->getEmail()],
        );
    }

    /**
     * Ends every active session of the user except the given one. Returns
     * the number of revoked sessions.
     */
    public function revokeOthers(User $user, string $currentToken): int
    {
        $revoked = $this->repository()->revokeOthers($user, $currentToken);

        if ($revoked > 0) {
            $this->revokeRememberTokens($user);
        }

        return $revoked;
    }

    /**
     * Revokes the session row identified by the session token (used on
     * logout).
     */
    public function revokeByToken(User $user, string $token): void
    {
        $row = $this->repository()->findActiveByToken($user, $token);

        if (null !== $row) {
            $row->setRevokedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }
    }

    /**
     * @return list<UserLoginSession>
     */
    public function activeFor(User $user): array
    {
        return $this->repository()->findActiveByUser($user);
    }

    /**
     * True when the request must be terminated (kicked).
     */
    public function mustTerminate(Request $request): bool
    {
        return (bool) $request->attributes->get(self::FORCE_LOGOUT_ATTRIBUTE, false);
    }

    private function terminate(Request $request, \Symfony\Component\HttpFoundation\Session\SessionInterface $session): void
    {
        // invalidate() keeps attributes (only rotates the id): the registry
        // token must be removed explicitly, otherwise the session would
        // survive the kick.
        $session->invalidate();
        $session->remove(self::SESSION_ATTRIBUTE);
        $request->attributes->set(self::FORCE_LOGOUT_ATTRIBUTE, true);
    }

    private function refresh(UserLoginSession $row, Request $request, bool $rememberMe): void
    {
        $row->setRememberMe($rememberMe);
        $row->setLastSeenAt(new \DateTimeImmutable());
        $this->applyRequestData($row, $request);
        $this->entityManager->flush();
    }

    private function applyRequestData(UserLoginSession $row, Request $request): void
    {
        $ip = $request->getClientIp();
        $userAgent = substr(trim((string) $request->headers->get('User-Agent', '')), 0, 500);
        $parsed = UserAgentParser::parse($request->headers->get('User-Agent'));

        $ipChanged = $ip !== $row->getIpAddress();
        $row->setIpAddress($ip);
        $row->setUserAgent($userAgent);
        $row->setBrowser($parsed['browser']);
        $row->setOs($parsed['os']);

        if ($ipChanged || null === $row->getGeoCountry()) {
            $geo = $this->geoIpLookup->lookup((string) $ip);
            $row->setGeoCity($geo['city']);
            $row->setGeoCountry($geo['country']);
        }
    }

    private function repository(): UserLoginSessionRepository
    {
        $repository = $this->entityManager->getRepository(UserLoginSession::class);
        \assert($repository instanceof UserLoginSessionRepository);

        return $repository;
    }
}

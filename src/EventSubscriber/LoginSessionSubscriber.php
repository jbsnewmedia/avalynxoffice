<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\EventSubscriber;

use AvalynxOffice\Service\Login\LoginSessionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * Maintains the concurrent-login registry: registers a row on every
 * interactive login, refreshes/kills the session on each request (kicked
 * sessions are terminated together with their remember-me cookie) and
 * revokes the row on logout.
 */
final readonly class LoginSessionSubscriber implements EventSubscriberInterface
{
    /** Default remember-me cookie name of the security bundle. */
    private const string REMEMBER_COOKIE = 'REMEMBERME';

    public function __construct(
        private LoginSessionService $loginSessionService,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            // Runs after LoginRememberMeSubscriber so the remember-me flag
            // of this attempt is already stored in the session.
            SecurityEvents::INTERACTIVE_LOGIN => ['onInteractiveLogin', -10],
            KernelEvents::REQUEST => ['onKernelRequest', 0],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        // Symfony 8 removed InteractiveLoginEvent::getToken(); the token is
        // already stored by the time the event is dispatched.
        $user = $this->resolveUser($this->tokenStorage->getToken());

        if (null === $user) {
            return;
        }

        $this->loginSessionService->register($user, $event->getRequest());
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->resolveUser($this->tokenStorage->getToken());

        if (null === $user) {
            return;
        }

        $tokenInSession = $event->getRequest()->getSession()->get(LoginSessionService::SESSION_ATTRIBUTE);

        $this->loginSessionService->handleRequest($user, $event->getRequest());

        if ($this->loginSessionService->mustTerminate($event->getRequest())) {
            $this->tokenStorage->setToken(null);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->loginSessionService->mustTerminate($event->getRequest())) {
            return;
        }

        // A kicked session must not be silently restored by the remember-me
        // cookie: remove it on the very request the session is terminated.
        $event->getResponse()->headers->clearCookie(self::REMEMBER_COOKIE, '/');
        foreach ($event->getResponse()->headers->getCookies() as $cookie) {
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $this->resolveUser($event->getToken());

        // The token storage is already cleared during logout; the event
        // carries the previous token.

        if (null === $user) {
            return;
        }

        $token = $event->getRequest()->getSession()->get(LoginSessionService::SESSION_ATTRIBUTE);

        if (is_string($token) && '' !== $token) {
            $this->loginSessionService->revokeByToken($user, $token);
        }
    }

    private function resolveUser(?TokenInterface $token): ?\AvalynxOffice\Entity\User
    {
        $user = $token?->getUser();

        return $user instanceof \AvalynxOffice\Entity\User ? $user : null;
    }
}

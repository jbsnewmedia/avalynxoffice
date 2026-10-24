<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * Remembers the "stay logged in" checkbox state of the last login attempt:
 * after a failed login the login page renders the checkbox exactly as the
 * user left it (Symfony itself only restores the last username).
 */
final class LoginRememberMeSubscriber implements EventSubscriberInterface
{
    public const string SESSION_KEY = 'login_remember_me';

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            // The failure event is dispatched by class name in Symfony 8.
            LoginFailureEvent::class => 'onLoginFailure',
            SecurityEvents::INTERACTIVE_LOGIN => 'onInteractiveLogin',
        ];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $this->remember($event->getRequest());
    }

    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        $this->remember($event->getRequest());
    }

    private function remember(Request $request): void
    {
        // Login events can be dispatched without a session (e.g. stateless
        // firewalls or direct test dispatches): nothing to remember then.
        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->set(self::SESSION_KEY, $request->request->getBoolean('_remember_me', false));
    }
}

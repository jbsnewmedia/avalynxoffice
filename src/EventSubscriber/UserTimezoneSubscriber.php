<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\EventSubscriber;

use AvalynxOffice\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;
use Twig\Extension\CoreExtension;

/**
 * Renders every twig date() in the time zone of the logged-in user. The
 * stored dates are UTC; the twig date extension defaults to the server time
 * zone (UTC as well), so without this subscriber users outside of UTC would
 * see shifted times. Templates that need more than the time zone (unified
 * formats) use the user_datetime filter instead.
 */
final readonly class UserTimezoneSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Environment $twig,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // The calendar module computes its day grid server side: switching
        // only the twig rendering time zone would shift the day keys out of
        // sync with the calculated grid. Time zone support for the calendar
        // grid is a follow-up on the service level.
        if (str_starts_with($event->getRequest()->getPathInfo(), '/calendar')) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User || null === $user->getTimezone()) {
            return;
        }

        try {
            $core = $this->twig->getExtension(CoreExtension::class);
            \assert($core instanceof CoreExtension);
            $core->setTimezone(new \DateTimeZone($user->getTimezone()));
        } catch (\Exception) {
            // An invalid stored time zone falls back to the system default.
        }
    }
}

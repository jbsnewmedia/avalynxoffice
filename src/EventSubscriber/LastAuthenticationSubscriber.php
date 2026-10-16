<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * Records every successful interactive authentication (web login, including
 * remember-me sign-in) as new seat activity. Together with the device
 * authentications recorded by avalynxofficeeas this implements the Active
 * Seat definition of the AvalynxOffice Public License (APL 1.0): an account
 * only counts as a seat while it authenticated within the last 30 calendar
 * days.
 */
final readonly class LastAuthenticationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            SecurityEvents::INTERACTIVE_LOGIN => 'onInteractiveLogin',
        ];
    }

    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();

        if (!$user instanceof User) {
            return;
        }

        $this->userRepository->recordAuthentication($user, new \DateTimeImmutable('now'));
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\EventSubscriber;

use AvalynxOffice\EventSubscriber\LoginRememberMeSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\SecurityEvents;

final class LoginRememberMeSubscriberTest extends TestCase
{
    public function testSubscriptionIsRegistered(): void
    {
        $events = LoginRememberMeSubscriber::getSubscribedEvents();

        self::assertSame([
            // The failure event is dispatched by class name in Symfony 8.
            LoginFailureEvent::class => 'onLoginFailure',
            SecurityEvents::INTERACTIVE_LOGIN => 'onInteractiveLogin',
        ], $events);
    }
}

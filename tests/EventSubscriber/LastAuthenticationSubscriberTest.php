<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\EventSubscriber;

use AvalynxOffice\Entity\User;
use AvalynxOffice\EventSubscriber\LastAuthenticationSubscriber;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;

#[\PHPUnit\Framework\Attributes\Group('integration')]
final class LastAuthenticationSubscriberTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
    }

    public function testSuccessfulAuthenticationIsRecorded(): void
    {
        $user = new User();
        $user->setEmail('login@example.com');
        $user->setPassword('hash');
        $this->em->persist($user);
        $this->em->flush();
        $userId = $user->getId();
        $this->em->clear();

        $event = new InteractiveLoginEvent(new Request(), new UsernamePasswordToken(
            $this->em->find(User::class, $userId),
            'main',
            ['ROLE_USER'],
        ));

        static::getContainer()->get('event_dispatcher')->dispatch($event, SecurityEvents::INTERACTIVE_LOGIN);

        $this->em->clear();

        /** @var User $reloaded */
        $reloaded = $this->em->find(User::class, $userId);
        self::assertInstanceOf(User::class, $reloaded);
        self::assertNotNull($reloaded->getLastAuthAt());
    }

    public function testNonUserTokenIsIgnored(): void
    {
        $event = new InteractiveLoginEvent(new Request(), new UsernamePasswordToken(
            new ForeignUser(),
            'main',
            ['ROLE_USER'],
        ));

        static::getContainer()->get('event_dispatcher')->dispatch($event, SecurityEvents::INTERACTIVE_LOGIN);

        self::assertSame(0, $this->em->getRepository(User::class)->countActiveSeats(new \DateTimeImmutable('now')));
    }

    public function testSubscriptionIsRegistered(): void
    {
        $events = LastAuthenticationSubscriber::getSubscribedEvents();

        self::assertSame([SecurityEvents::INTERACTIVE_LOGIN => 'onInteractiveLogin'], $events);
    }
}

/**
 * Security user that is deliberately not an AvalynxOffice\Entity\User, so the
 * subscriber's instanceof guard can be tested.
 */
final class ForeignUser implements UserInterface
{
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'foreign@example.com';
    }
}

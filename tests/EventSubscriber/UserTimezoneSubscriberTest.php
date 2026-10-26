<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\EventSubscriber;

use AvalynxOffice\Entity\User;
use AvalynxOffice\EventSubscriber\UserTimezoneSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;
use Twig\Extension\CoreExtension;

#[Group('integration')]
final class UserTimezoneSubscriberTest extends KernelTestCase
{
    private UserTimezoneSubscriber $subscriber;

    private Environment $twig;

    private TokenStorageInterface $tokenStorage;

    private KernelInterface $httpKernel;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = self::getContainer();
        $this->subscriber = $container->get(UserTimezoneSubscriber::class);
        \assert($this->subscriber instanceof UserTimezoneSubscriber);
        $this->twig = $container->get('twig');
        \assert($this->twig instanceof Environment);
        $this->tokenStorage = $container->get('security.token_storage');
        \assert($this->tokenStorage instanceof TokenStorageInterface);
        $this->httpKernel = self::getContainer()->get('kernel');
        \assert($this->httpKernel instanceof KernelInterface);
        // Reset the twig date extension to its default time zone.
        $core = $this->twig->getExtension(CoreExtension::class);
        \assert($core instanceof CoreExtension);
        $core->setTimezone(new \DateTimeZone('UTC'));
    }

    private function controllerEvent(Request $request, ?User $user): ControllerEvent
    {
        $event = new ControllerEvent(
            $this->httpKernel,
            static fn (): array => [],
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        if (null !== $user) {
            $tokenStorage = self::getContainer()->get('security.token_storage');
            \assert($tokenStorage instanceof \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface);
            $tokenStorage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        return $event;
    }

    public function testSetsTwigDateExtensionToUserTimezone(): void
    {
        $user = new User();
        $user->setTimezone('Europe/Berlin');

        $request = new Request(server: ['REQUEST_URI' => '/mails']);
        $this->subscriber->onKernelController($this->controllerEvent($request, $user));

        $core = $this->twig->getExtension(CoreExtension::class);
        \assert($core instanceof CoreExtension);
        self::assertSame('Europe/Berlin', $core->getTimezone()->getName());
    }

    public function testKeepsDefaultWithoutUserTimezone(): void
    {
        $user = new User();

        $request = new Request(server: ['REQUEST_URI' => '/mails']);
        $this->subscriber->onKernelController($this->controllerEvent($request, $user));

        $core = $this->twig->getExtension(CoreExtension::class);
        \assert($core instanceof CoreExtension);
        self::assertSame('UTC', $core->getTimezone()->getName());
    }

    public function testKeepsDefaultWithoutUser(): void
    {
        $request = new Request(server: ['REQUEST_URI' => '/login']);
        $this->subscriber->onKernelController($this->controllerEvent($request, null));

        $core = $this->twig->getExtension(CoreExtension::class);
        \assert($core instanceof CoreExtension);
        self::assertSame('UTC', $core->getTimezone()->getName());
    }

    public function testKeepsDefaultOnCalendarModule(): void
    {
        $user = new User();
        $user->setTimezone('Europe/Berlin');

        $request = new Request(server: ['REQUEST_URI' => '/calendar']);
        $this->subscriber->onKernelController($this->controllerEvent($request, $user));

        // The calendar day grid is computed server side: the module keeps
        // the system time zone until the grid is timezone aware.
        $core = $this->twig->getExtension(CoreExtension::class);
        \assert($core instanceof CoreExtension);
        self::assertSame('UTC', $core->getTimezone()->getName());
    }

    public function testInvalidStoredTimezoneKeepsDefault(): void
    {
        $user = new User();
        $user->setTimezone('Mars/Olympus_Mons');

        $request = new Request(server: ['REQUEST_URI' => '/mails']);
        $this->subscriber->onKernelController($this->controllerEvent($request, $user));

        $core = $this->twig->getExtension(CoreExtension::class);
        \assert($core instanceof CoreExtension);
        self::assertSame('UTC', $core->getTimezone()->getName());
    }
}

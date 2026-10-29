<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Twig;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use AvalynxOffice\Twig\UserDateTimeExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Group('integration')]
final class UserDateTimeExtensionTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private UserDateTimeExtension $extension;

    private TokenStorageInterface $tokenStorage;

    private TranslatorInterface $translator;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);

        $this->extension = $container->get(UserDateTimeExtension::class);
        \assert($this->extension instanceof UserDateTimeExtension);
        $this->tokenStorage = $container->get('security.token_storage');
        \assert($this->tokenStorage instanceof TokenStorageInterface);
        $this->tokenStorage->setToken(null);
        $this->translator = $container->get(TranslatorInterface::class);
        \assert($this->translator instanceof TranslatorInterface);
    }

    private function loginUser(User $user): void
    {
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    public function testNullRendersEmptyString(): void
    {
        self::assertSame('', $this->extension->userDatetime(null));
        self::assertSame('', $this->extension->userDatetime(''));
    }

    public function testUtcUserRendersUtcByDefault(): void
    {
        $user = new User();
        $user->setEmail('utc@example.com');
        $user->setPassword('secret');
        $this->em->persist($user);
        $this->em->flush();

        $this->loginUser($user);

        $dateTime = new \DateTimeImmutable('2026-10-26 10:00:00', new \DateTimeZone('UTC'));
        self::assertSame('26.10.2026 10:00', $this->extension->userDatetime($dateTime, 'short'));
        self::assertSame('26.10.2026, 10:00:00', $this->extension->userDatetime($dateTime, 'medium'));
        self::assertSame('26.10.2026', $this->extension->userDatetime($dateTime, 'date'));
        self::assertSame('10:00', $this->extension->userDatetime($dateTime, 'time'));
        self::assertSame('2026-10-26 10:00:00', $this->extension->userDatetime($dateTime, 'iso'));
    }

    public function testBerlinUserSeesConvertedTime(): void
    {
        $user = new User();
        $user->setEmail('berlin@example.com');
        $user->setPassword('secret');
        $user->setTimezone('Europe/Berlin');
        $this->em->persist($user);
        $this->em->flush();

        $this->loginUser($user);

        // 10:00 UTC is 11:00 in Europe/Berlin (CET, UTC+1 after the DST
        // switch on 2026-10-25).
        $dateTime = new \DateTimeImmutable('2026-10-26 10:00:00', new \DateTimeZone('UTC'));
        self::assertSame('26.10.2026 11:00', $this->extension->userDatetime($dateTime, 'short'));
    }

    public function testDefaultVariantComesFromUserPreference(): void
    {
        $user = new User();
        $user->setEmail('format@example.com');
        $user->setPassword('secret');
        $user->setTimezone('Europe/Berlin');
        $user->setTimeFormat('medium');
        $this->em->persist($user);
        $this->em->flush();

        $this->loginUser($user);

        $dateTime = new \DateTimeImmutable('2026-10-26 10:00:00', new \DateTimeZone('UTC'));
        self::assertSame('26.10.2026, 11:00:00', $this->extension->userDatetime($dateTime));
    }

    public function testUnknownVariantFallsBackToDefault(): void
    {
        $dateTime = new \DateTimeImmutable('2026-10-26 10:00:00', new \DateTimeZone('UTC'));
        self::assertSame('26.10.2026 10:00', $this->extension->userDatetime($dateTime, 'does-not-exist'));
    }

    public function testLongVariantAppendsLocalizedClockSuffix(): void
    {
        $dateTime = new \DateTimeImmutable('2026-10-26 10:00:00', new \DateTimeZone('UTC'));

        $rendered = $this->extension->userDatetime($dateTime, 'long');

        self::assertSame('26.10.2026, 10:00:00 '.$this->translator->trans('common.oclock'), $rendered);
    }

    public function testStringInputIsParsedAsUtc(): void
    {
        $user = new User();
        $user->setEmail('string@example.com');
        $user->setPassword('secret');
        $user->setTimezone('Europe/Berlin');
        $this->em->persist($user);
        $this->em->flush();

        $this->loginUser($user);

        self::assertSame('26.10.2026 11:00', $this->extension->userDatetime('2026-10-26 10:00:00', 'short'));
        self::assertSame('', $this->extension->userDatetime('', 'short'));
    }

    public function testInvalidStoredTimezoneFallsBackToUtc(): void
    {
        $user = new User();
        $user->setEmail('broken@example.com');
        $user->setPassword('secret');
        $user->setTimezone('Mars/Olympus_Mons');
        $this->em->persist($user);
        $this->em->flush();

        $this->loginUser($user);

        $dateTime = new \DateTimeImmutable('2026-10-26 10:00:00', new \DateTimeZone('UTC'));
        self::assertSame('26.10.2026 10:00', $this->extension->userDatetime($dateTime, 'short'));
    }

    public function testGetFiltersRegistersTheUserDatetimeFilter(): void
    {
        $filters = $this->extension->getFilters();

        self::assertCount(1, $filters);
        self::assertSame('user_datetime', $filters[0]->getName());
    }

    public function testNonDateTimeValueRendersEmptyString(): void
    {
        self::assertSame('', $this->extension->userDatetime(12345));
        self::assertSame('', $this->extension->userDatetime(['not', 'a', 'date']));
    }
}

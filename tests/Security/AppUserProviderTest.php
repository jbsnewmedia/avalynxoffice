<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Security;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Security\AppUserProvider;
use AvalynxOffice\Service\Idn\IdnService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * The login provider matches the identifier the user typed against the
 * stored punycode email: IDN input ("user@bästoff.de") finds the user whose
 * email is stored as "user@xn--bstoff-bua.de" — independently of whether
 * the browser normalized the input.
 */
#[Group('integration')]
final class AppUserProviderTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $em;

    private AppUserProvider $provider;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);

        $user = new User();
        $user->setEmail('user@xn--bstoff-bua.de');
        $user->setPassword('hash-not-checked-here');
        $this->em->persist($user);
        $this->em->flush();

        $this->provider = new AppUserProvider($this->em, new IdnService());
    }

    public function testLoadsUserByUnicodeEmail(): void
    {
        $loaded = $this->provider->loadUserByIdentifier('user@bästoff.de');

        self::assertSame('user@xn--bstoff-bua.de', $loaded->getUserIdentifier());
    }

    public function testLoadsUserByPunycodeEmail(): void
    {
        $loaded = $this->provider->loadUserByIdentifier('user@xn--bstoff-bua.de');

        self::assertSame('user@xn--bstoff-bua.de', $loaded->getUserIdentifier());
    }

    public function testLoadsUserCaseInsensitive(): void
    {
        $loaded = $this->provider->loadUserByIdentifier('User@Bästoff.DE');

        self::assertSame('user@xn--bstoff-bua.de', $loaded->getUserIdentifier());
    }

    public function testThrowsForUnknownEmail(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->provider->loadUserByIdentifier('nobody@example.com');
    }

    public function testRefreshUserReloadsFromDatabase(): void
    {
        $loaded = $this->provider->loadUserByIdentifier('user@bästoff.de');

        self::assertSame($loaded->getUserIdentifier(), $this->provider->refreshUser($loaded)->getUserIdentifier());
    }

    public function testSupportsOnlyUserClass(): void
    {
        self::assertTrue($this->provider->supportsClass(User::class));
        self::assertFalse($this->provider->supportsClass(\stdClass::class));
    }
}

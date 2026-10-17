<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Security;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class UserCheckerTest extends TestCase
{
    public function testCheckPreAuthAllowsActiveUsers(): void
    {
        $checker = new UserChecker();
        $user = new User();
        $user->setEmail('active@example.com');

        $checker->checkPreAuth($user);
        $checker->checkPostAuth($user);

        self::addToAssertionCount(1);
    }

    public function testCheckPreAuthBlocksDeactivatedUsers(): void
    {
        $checker = new UserChecker();
        $user = new User();
        $user->setEmail('inactive@example.com');
        $user->setActive(false);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $checker->checkPreAuth($user);
    }

    public function testCheckPreAuthIgnoresForeignUserObjects(): void
    {
        $checker = new UserChecker();
        $foreign = $this->createStub(\Symfony\Component\Security\Core\User\UserInterface::class);

        $checker->checkPreAuth($foreign);
        $checker->checkPostAuth($foreign, null);

        self::addToAssertionCount(1);
    }
}

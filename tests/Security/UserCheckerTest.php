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

    /**
     * The message must be the generic "Invalid credentials." the
     * authenticator uses for wrong passwords: a deactivated account must not
     * be distinguishable from a non-existent one.
     */
    public function testDeactivatedUserMessageIsIndistinguishableFromBadCredentials(): void
    {
        $checker = new UserChecker();
        $user = new User();
        $user->setEmail('inactive@example.com');
        $user->setActive(false);

        try {
            $checker->checkPreAuth($user);
            self::fail('Expected a CustomUserMessageAccountStatusException.');
        } catch (CustomUserMessageAccountStatusException $exception) {
            self::assertSame('Invalid credentials.', $exception->getMessageKey());
            self::assertSame(new \Symfony\Component\Security\Core\Exception\BadCredentialsException()->getMessageKey(), $exception->getMessageKey());
            self::assertStringNotContainsStringIgnoringCase('deactivated', $exception->getMessageKey());
        }
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

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\UserRepository;
use AvalynxOffice\Service\UserService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    #[Group('unit')]
    public function testFirstUserWithoutExistingAdminBecomesAdmin(): void
    {
        $service = $this->createService([]);

        $user = new User();
        $service->applyInitialRoles($user);

        // getRoles() always ensures ROLE_USER alongside the administrator role.
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    #[Group('unit')]
    public function testUserWithExistingAdminStaysRegularUser(): void
    {
        $service = $this->createService([new User()]);

        $user = new User();
        $service->applyInitialRoles($user);

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    #[Group('unit')]
    public function testLastActiveAdminIsDetected(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);

        $service = $this->createService([$admin]);

        self::assertTrue($service->isLastActiveAdmin($admin));
    }

    #[Group('unit')]
    public function testDeactivatedAdminIsNotTheLastActiveAdmin(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setActive(false);

        $service = $this->createService([]);

        self::assertFalse($service->isLastActiveAdmin($admin));
    }

    #[Group('unit')]
    public function testRegularUserIsNotTheLastActiveAdmin(): void
    {
        $user = new User();

        $service = $this->createService([$user]);

        self::assertFalse($service->isLastActiveAdmin($user));
    }

    #[Group('unit')]
    public function testToggleAdminGrantsRoleToRegularUser(): void
    {
        $user = new User();
        $service = $this->createService([$user]);

        $service->toggleAdmin($user);

        self::assertContains('ROLE_ADMIN', $user->getRoles());
    }

    #[Group('unit')]
    public function testToggleAdminRevokesRoleWhenOtherAdminsRemain(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $other = new User();
        $other->setRoles(['ROLE_ADMIN']);

        $service = $this->createService([$admin, $other]);

        $service->toggleAdmin($admin);

        self::assertNotContains('ROLE_ADMIN', $admin->getRoles());
    }

    #[Group('unit')]
    public function testToggleAdminRejectsRevocationForLastActiveAdmin(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);

        $service = $this->createService([$admin]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('The last active administrator cannot lose the administrator role.');
        $service->toggleAdmin($admin);
    }

    #[Group('unit')]
    public function testToggleAdminRevokesRoleForInactiveAdminWithoutOtherAdmins(): void
    {
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setActive(false);

        $service = $this->createService([]);

        $service->toggleAdmin($admin);

        self::assertNotContains('ROLE_ADMIN', $admin->getRoles());
    }

    private function createService(array $activeAdmins): UserService
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findActiveAdmins')->willReturn($activeAdmins);

        return new UserService($repository);
    }
}

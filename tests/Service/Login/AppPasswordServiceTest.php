<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Login;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Entity\UserAppPassword;
use AvalynxOffice\Repository\UserAppPasswordRepository;
use AvalynxOffice\Service\Login\AppPasswordService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AppPasswordServiceTest extends TestCase
{
    public function testGenerateProducesGroupedUnambiguousPassword(): void
    {
        $service = new AppPasswordService($this->createStub(EntityManagerInterface::class), $this->createStub(UserAppPasswordRepository::class));

        $password = $service->generate();

        self::assertMatchesRegularExpression('/^[a-km-np-z2-9]{5}(-[a-km-np-z2-9]{5}){3}$/', $password);
        self::assertNotSame($password, $service->generate());
    }

    public function testNormalizeIgnoresDashesWhitespaceAndCase(): void
    {
        self::assertSame('abcdeabcdeabcdeabcde', AppPasswordService::normalize('ABCDE-abcde abcde - abcde'));
        self::assertSame('abcdeabcdeabcdeabcde', AppPasswordService::normalize("abcde-abcde-abcde-abcde\n"));
    }

    public function testCreatePersistsHashOfNormalizedPassword(): void
    {
        $user = new User()->setEmail('user@example.com');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(UserAppPassword::class));
        $entityManager->expects(self::once())->method('flush');
        $repository = $this->createStub(UserAppPasswordRepository::class);
        $repository->method('findActiveByUser')->willReturn([]);

        [$row, $plain] = new AppPasswordService($entityManager, $repository)->create($user, '  Tablet ');

        self::assertSame('Tablet', $row->getLabel());
        self::assertSame($user, $row->getUser());
        self::assertNotSame($plain, $row->getPasswordHash());
        self::assertTrue(password_verify(AppPasswordService::normalize($plain), $row->getPasswordHash()));
        self::assertFalse(password_verify('wrong', $row->getPasswordHash()));
    }

    public function testCreateRejectsEmptyLabelAndLimit(): void
    {
        $user = new User()->setEmail('user@example.com');
        $repository = $this->createStub(UserAppPasswordRepository::class);
        $repository->method('findActiveByUser')->willReturn(array_fill(0, AppPasswordService::MAX_ACTIVE, new UserAppPassword()));
        $service = new AppPasswordService($this->createStub(EntityManagerInterface::class), $repository);

        self::assertFalse($service->canCreate($user));

        try {
            $service->create($user, '');
            self::fail('Empty label must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\RuntimeException::class);
        $service->create($user, 'Phone');
    }
}

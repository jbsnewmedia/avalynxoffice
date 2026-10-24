<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Repository;

use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\Body;
use AvalynxOffice\Entity\EmailAddressLink;
use AvalynxOffice\Entity\EmailAttachmentLink;
use AvalynxOffice\Entity\EmailBodyLink;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\ConnectorConfig;
use AvalynxOffice\Repository\AttachmentRepository;
use AvalynxOffice\Repository\BodyRepository;
use AvalynxOffice\Repository\EmailAddressLinkRepository;
use AvalynxOffice\Repository\EmailAttachmentLinkRepository;
use AvalynxOffice\Repository\EmailBodyLinkRepository;
use AvalynxOffice\Repository\FolderRepository;
use AvalynxOffice\Repository\ConnectorRepository;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use AvalynxOffice\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepositoryInstantiationTest extends TestCase
{
    #[DataProvider('repositoryProvider')]
    public function testRepositoryCanBeInstantiatedAndReportsManagedClass(string $repositoryClass, string $entityClass): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturnCallback(
            static fn (string $class): ClassMetadata => new ClassMetadata($class)
        );

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')
            ->willReturnCallback(static fn (string $class): ?EntityManagerInterface => $entityClass === $class ? $entityManager : null);

        /** @var \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository $repository */
        $repository = new $repositoryClass($registry);

        self::assertSame($entityClass, $repository->getClassName());
    }

    /**
     * @return iterable<string, array{0: class-string, 1: class-string}>
     */
    public static function repositoryProvider(): iterable
    {
        yield 'attachment' => [AttachmentRepository::class, Attachment::class];
        yield 'body' => [BodyRepository::class, Body::class];
        yield 'email-address-link' => [EmailAddressLinkRepository::class, EmailAddressLink::class];
        yield 'email-attachment-link' => [EmailAttachmentLinkRepository::class, EmailAttachmentLink::class];
        yield 'email-body-link' => [EmailBodyLinkRepository::class, EmailBodyLink::class];
        yield 'folder' => [FolderRepository::class, Folder::class];
        yield 'account' => [AccountRepository::class, Account::class];
        yield 'account-connector' => [ConnectorRepository::class, Connector::class];
        yield 'connector-config' => [ConnectorConfigRepository::class, ConnectorConfig::class];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Attachment;
use App\Entity\Body;
use App\Entity\EmailAddressLink;
use App\Entity\EmailAttachmentLink;
use App\Entity\EmailBodyLink;
use App\Entity\Folder;
use App\Entity\Mailbox;
use App\Entity\MailboxConnector;
use App\Repository\AttachmentRepository;
use App\Repository\BodyRepository;
use App\Repository\EmailAddressLinkRepository;
use App\Repository\EmailAttachmentLinkRepository;
use App\Repository\EmailBodyLinkRepository;
use App\Repository\FolderRepository;
use App\Repository\MailboxConnectorRepository;
use App\Repository\MailboxRepository;
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
        yield 'mailbox' => [MailboxRepository::class, Mailbox::class];
        yield 'mailbox-connector' => [MailboxConnectorRepository::class, MailboxConnector::class];
    }
}

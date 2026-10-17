<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Mail;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\MailboxConnector;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\Pop3Fetcher;
use AvalynxOffice\Service\Mail\Pop3StorageFactory;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Laminas\Mail\Header\HeaderInterface;
use Laminas\Mail\Headers;
use Laminas\Mail\Storage\Message as StorageMessage;
use Laminas\Mail\Storage\Pop3 as Pop3Storage;
use PHPUnit\Framework\TestCase;

final class Pop3FetcherFetchTest extends TestCase
{
    public function testFetchForConnectorReturnsEarlyWithoutMailboxOrCredentials(): void
    {
        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $this->createStub(Pop3StorageFactory::class),
        );

        $withoutMailbox = (new MailboxConnector())
            ->setPop3Host('mail.example.com')
            ->setPop3Username('user')
            ->setPop3Password('pass');
        $withoutCredentials = (new MailboxConnector())
            ->setMailbox((new Mailbox())->setName('Support')->setEmail('support@example.com'))
            ->setPop3Host('mail.example.com');

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 0], $fetcher->fetchForConnector($withoutMailbox));
        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 0], $fetcher->fetchForConnector($withoutCredentials));
    }

    public function testFetchForConnectorReturnsEarlyOnConnectionFailure(): void
    {
        $factory = $this->createMock(Pop3StorageFactory::class);
        $factory
            ->expects(self::once())
            ->method('create')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $factory,
        );

        $connector = $this->createConnector();

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 0], $fetcher->fetchForConnector($connector));
    }

    public function testFetchForConnectorSkipsAlreadyImportedMessages(): void
    {
        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('countMessages')->willReturn(1);
        $storage->method('getUniqueId')->with(1)->willReturn('uid-1');
        $storage->expects(self::never())->method('getMessage');

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createMock(EmailRepository::class);
        $connector = $this->createConnector();
        $emailRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['importKey' => (string) $connector->getId().':uid-1'])
            ->willReturn(new \stdClass());

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $emailRepository,
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $factory,
        );

        self::assertSame(['imported' => 0, 'skipped' => 1, 'errors' => 0], $fetcher->fetchForConnector($connector));
    }

    public function testFetchForConnectorImportsNewMessage(): void
    {
        $mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');
        $inboxFolder = (new Folder())->setName('Postfach');
        $mailbox->setInboxFolder($inboxFolder);
        $connector = $this->createConnector($mailbox);

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('countMessages')->willReturn(1);
        $storage->expects(self::once())->method('getUniqueId')->with(1)->willReturn('uid-1');
        $storage->expects(self::once())->method('getMessage')->with(1)->willReturn($this->createMessageStub());

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createMock(EmailRepository::class);
        $emailRepository
            ->expects(self::exactly(2))
            ->method('findOneBy')
            ->willReturnCallback(static fn (array $criteria): mixed => match (array_key_first($criteria)) {
                'importKey', 'contentHash' => null,
            });

        $addressRepository = $this->createStub(EntityRepository::class);
        $addressRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Address::class)->willReturn($addressRepository);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $entityManager->method('isOpen')->willReturn(true);

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::exactly(2))->method('ensureSystemFolder');
        $folderManager->expects(self::once())->method('syncEmailSystemFolders');

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
        );

        $result = $fetcher->fetchForConnector($connector);

        self::assertSame(['imported' => 1, 'skipped' => 0, 'errors' => 0], $result);
    }

    public function testFetchForConnectorSkipsMessageByContentHash(): void
    {
        $mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');
        $connector = $this->createConnector($mailbox);

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('countMessages')->willReturn(1);
        $storage->expects(self::once())->method('getUniqueId')->with(1)->willReturn('uid-1');
        $storage->expects(self::once())->method('getMessage')->with(1)->willReturn($this->createMessageStub());

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createMock(EmailRepository::class);
        $emailRepository
            ->expects(self::exactly(2))
            ->method('findOneBy')
            ->willReturnCallback(static fn (array $criteria): mixed => match (array_key_first($criteria)) {
                'importKey' => null,
                'contentHash' => new \stdClass(),
            });

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::exactly(2))->method('ensureSystemFolder');

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
        );

        self::assertSame(['imported' => 0, 'skipped' => 1, 'errors' => 0], $fetcher->fetchForConnector($connector));
    }


    public function testFetchForConnectorPassesTlsConfigAndProcessesOldestMessagesWithLimit(): void
    {
        $connector = $this->createConnector();
        $connector->setPop3Security('tls');
        $processed = [];

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('countMessages')->willReturn(3);
        $storage->method('getUniqueId')->willReturnCallback(function (int $num) use (&$processed): string {
            $processed[] = $num;

            return 'uid-'.$num;
        });
        $storage->expects(self::never())->method('getMessage');

        $factory = $this->createMock(Pop3StorageFactory::class);
        $factory
            ->expects(self::once())
            ->method('create')
            ->with(self::callback(static fn (array $config): bool => 'TLS' === $config['ssl']))
            ->willReturn($storage);

        $emailRepository = $this->createStub(EmailRepository::class);
        $emailRepository->method('findOneBy')->willReturn(new \stdClass());

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::exactly(2))->method('ensureSystemFolder');

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
        );

        self::assertSame(['imported' => 0, 'skipped' => 2, 'errors' => 0], $fetcher->fetchForConnector($connector, 2, false, true));
        self::assertSame([1, 2], $processed);
    }

    public function testFetchForConnectorProcessesAllOldestFirstWithoutLimit(): void
    {
        $connector = $this->createConnector();
        $processed = [];

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('countMessages')->willReturn(2);
        $storage->method('getUniqueId')->willReturnCallback(function (int $num) use (&$processed): string {
            $processed[] = $num;

            return 'uid-'.$num;
        });
        $storage->expects(self::never())->method('getMessage');

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createStub(EmailRepository::class);
        $emailRepository->method('findOneBy')->willReturn(new \stdClass());

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::exactly(2))->method('ensureSystemFolder');

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
        );

        self::assertSame(['imported' => 0, 'skipped' => 2, 'errors' => 0], $fetcher->fetchForConnector($connector, null, false, true));
        self::assertSame([1, 2], $processed);
    }

    public function testFetchForConnectorContinuesWhenOpenEntityManagerReportsUniqueConstraint(): void
    {
        $mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');
        $mailbox->setInboxFolder((new Folder())->setName('Postfach'));
        $connector = $this->createConnector($mailbox);

        $storage = $this->createStub(Pop3Storage::class);
        $storage->method('countMessages')->willReturn(1);
        $storage->method('getUniqueId')->willReturn('uid-1');
        $storage->method('getMessage')->willReturn($this->createMessageStub());

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createStub(EmailRepository::class);
        $emailRepository->method('findOneBy')->willReturn(null);

        $addressRepository = $this->createStub(EntityRepository::class);
        $addressRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Address::class)->willReturn($addressRepository);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::once())->method('flush')->willThrowException($this->createUniqueConstraintViolationException());
        $entityManager->method('isOpen')->willReturn(true);

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::exactly(2))->method('ensureSystemFolder');
        $folderManager->expects(self::once())->method('syncEmailSystemFolders');

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
        );

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 0], $fetcher->fetchForConnector($connector));
    }

    public function testFetchForConnectorCountsErrorWhenClosedEntityManagerReportsUniqueConstraint(): void
    {
        $mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');
        $mailbox->setInboxFolder((new Folder())->setName('Postfach'));
        $connector = $this->createConnector($mailbox);

        $storage = $this->createStub(Pop3Storage::class);
        $storage->method('countMessages')->willReturn(1);
        $storage->method('getUniqueId')->willReturn('uid-1');
        $storage->method('getMessage')->willReturn($this->createMessageStub());

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createStub(EmailRepository::class);
        $emailRepository->method('findOneBy')->willReturn(null);

        $addressRepository = $this->createStub(EntityRepository::class);
        $addressRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Address::class)->willReturn($addressRepository);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::once())->method('flush')->willThrowException($this->createUniqueConstraintViolationException());
        $entityManager->method('isOpen')->willReturn(false);

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::exactly(2))->method('ensureSystemFolder');
        $folderManager->expects(self::once())->method('syncEmailSystemFolders');

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
        );

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 1], $fetcher->fetchForConnector($connector));
    }

    public function testFetchForConnectorDeletesImportedMessagesAndUsesNewestOrderWithLimit(): void
    {
        $mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');
        $mailbox->setInboxFolder((new Folder())->setName('Postfach'));
        $connector = $this->createConnector($mailbox);
        $processed = [];

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('countMessages')->willReturn(3);
        $storage->method('getUniqueId')->willReturnCallback(function (int $num) use (&$processed): string {
            $processed[] = $num;

            return 'uid-'.$num;
        });
        $storage->method('getMessage')->willReturn($this->createMessageStub());
        $storage->expects(self::exactly(2))->method('removeMessage')->with(self::greaterThan(0));

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createStub(EmailRepository::class);
        $emailRepository->method('findOneBy')->willReturn(null);

        $addressRepository = $this->createStub(EntityRepository::class);
        $addressRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Address::class)->willReturn($addressRepository);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::exactly(2))->method('flush');
        $entityManager->method('isOpen')->willReturn(true);

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::exactly(2))->method('ensureSystemFolder');
        $folderManager->expects(self::exactly(2))->method('syncEmailSystemFolders');

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
        );

        self::assertSame(['imported' => 2, 'skipped' => 0, 'errors' => 0], $fetcher->fetchForConnector($connector, 2, true));
        self::assertSame([3, 2], $processed);
    }

    public function testFetchForConnectorStopsAfterErrorWhenEntityManagerCloses(): void
    {
        $connector = $this->createConnector();
        $processed = [];

        $storage = $this->createStub(Pop3Storage::class);
        $storage->method('countMessages')->willReturn(2);
        $storage->method('getUniqueId')->willReturnCallback(function (int $num) use (&$processed): string {
            $processed[] = $num;

            return 'uid-'.$num;
        });
        $storage->method('getMessage')->willThrowException(new \RuntimeException('broken message'));

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::exactly(2))->method('ensureSystemFolder');

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $this->createStub(EmailRepository::class),
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
        );

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 1], $fetcher->fetchForConnector($connector));
        self::assertSame([2], $processed);
    }


    private function createUniqueConstraintViolationException(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(new class('duplicate') extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return '23000';
            }
        }, null);
    }

    private function createConnector(?Mailbox $mailbox = null): MailboxConnector
    {
        return (new MailboxConnector())
            ->setMailbox($mailbox ?? (new Mailbox())->setName('Support')->setEmail('support@example.com'))
            ->setEmail('support@example.com')
            ->setPop3Host('mail.example.com')
            ->setPop3Port(110)
            ->setPop3Username('user')
            ->setPop3Password('pass');
    }

    private function createMessageStub(): StorageMessage
    {
        $message = $this->createStub(StorageMessage::class);
        $headers = $this->createStub(Headers::class);
        $message->method('getHeaders')->willReturn($headers);
        $message->method('getContent')->willReturn('Plain text body');
        $message->method('isMultipart')->willReturn(false);

        $headers->method('toString')->willReturn("Subject: Test subject\nFrom: Sender <sender@example.com>\nDate: Mon, 01 Jan 2024 12:00:00 +0000");
        $headers
            ->method('has')
            ->willReturnCallback(static fn (string $name): bool => in_array($name, ['subject', 'from', 'date', 'content-type'], true));
        $headers
            ->method('get')
            ->willReturnCallback(function (string $name): ?HeaderInterface {
                $header = $this->createStub(HeaderInterface::class);
                $header->method('getFieldValue')->willReturn(match ($name) {
                    'subject' => 'Test subject',
                    'from' => 'Sender <sender@example.com>',
                    'date' => 'Mon, 01 Jan 2024 12:00:00 +0000',
                    'content-type' => 'text/plain; charset=UTF-8',
                    default => '',
                });

                return $header;
            });

        return $message;
    }
}

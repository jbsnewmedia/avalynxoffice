<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\Pop3ConnectorBundle\Service;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use JBSNewMedia\Pop3ConnectorBundle\Service\Pop3Fetcher;
use AvalynxOffice\Service\Mail\MailRoutingService;
use JBSNewMedia\Pop3ConnectorBundle\Service\Pop3StorageFactory;
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
    public function testFetchForConnectorReturnsEarlyWithoutCredentials(): void
    {
        $fetcher = $this->createFetcher();

        $withoutCredentials = (new Connector())
            ->setName('Support')
            ->setDomain('example.com');

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 0], $fetcher->fetch($withoutCredentials, self::partialConfig(), self::request()));
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
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );

        $connector = $this->createConnector();

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 0], $fetcher->fetch($connector, self::config(), self::request()));
    }

    public function testFetchForConnectorSkipsAlreadyImportedMessages(): void
    {
        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-1']);
        $storage->expects(self::never())->method('getMessage');

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createMock(EmailRepository::class);
        $emailRepository->method('importKeysForConnector')->willReturn([]);
        $connector = $this->createConnector();
        $emailRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['importKey' => (string) $connector->getId().':uid-1'])
            ->willReturn(new \stdClass());

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::never())->method('ensureSystemFolder');

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );

        self::assertSame(['imported' => 0, 'skipped' => 1, 'errors' => 0], $fetcher->fetch($connector, self::config(), self::request()));
    }

    public function testFetchForConnectorImportsNewMessage(): void
    {
        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $inboxFolder = (new Folder())->setName('Postfach');
        $account->setInboxFolder($inboxFolder);
        $connector = $this->createConnector($account);

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-1']);
        $storage->expects(self::once())->method('getMessage')->with(1)->willReturn($this->createMessageStub());

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createMock(EmailRepository::class);
        $emailRepository->method('importKeysForConnector')->willReturn([]);
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
            $this->createRoutingService($account),
            $this->createStub(ConnectorConfigRepository::class),
        );

        $result = $fetcher->fetch($connector, self::config(), self::request());

        self::assertSame(['imported' => 1, 'skipped' => 0, 'errors' => 0], $result);
    }

    public function testFetchForConnectorCountsErrorWhenNoRouteTargetExists(): void
    {
        $connector = $this->createConnector();

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-1']);
        $storage->method('getMessage')->willReturn($this->createMessageStub());
        $storage->expects(self::never())->method('removeMessage');

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createStub(EmailRepository::class);
        $emailRepository->method('importKeysForConnector')->willReturn([]);
        $emailRepository->method('findOneBy')->willReturn(null);

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::never())->method('ensureSystemFolder');

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
            $this->createRoutingService(null),
            $this->createStub(ConnectorConfigRepository::class),
        );

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 1], $fetcher->fetch($connector, self::config(), self::request()));
    }

    public function testFetchForConnectorSkipsMessageByContentHash(): void
    {
        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $connector = $this->createConnector($account);

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-1']);
        $storage->expects(self::once())->method('getMessage')->with(1)->willReturn($this->createMessageStub());

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createMock(EmailRepository::class);
        $emailRepository->method('importKeysForConnector')->willReturn([]);
        $emailRepository
            ->expects(self::exactly(2))
            ->method('findOneBy')
            ->willReturnCallback(static fn (array $criteria): mixed => match (array_key_first($criteria)) {
                'importKey' => null,
                'contentHash' => new \stdClass(),
            });

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::never())->method('ensureSystemFolder');

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
            $this->createRoutingService($account),
            $this->createStub(ConnectorConfigRepository::class),
        );

        self::assertSame(['imported' => 0, 'skipped' => 1, 'errors' => 0], $fetcher->fetch($connector, self::config(), self::request()));
    }


    public function testFetchForConnectorPassesTlsConfigAndProcessesOldestMessagesWithLimit(): void
    {
        $connector = $this->createConnector();
        $processed = [];

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-1', 2 => 'uid-2', 3 => 'uid-3']);
        $storage->expects(self::never())->method('getMessage');

        $factory = $this->createMock(Pop3StorageFactory::class);
        $factory
            ->expects(self::once())
            ->method('create')
            ->with(self::callback(static fn (array $config): bool => 'TLS' === $config['ssl']))
            ->willReturn($storage);

        $emailRepository = $this->createMock(EmailRepository::class);
        $emailRepository->method('importKeysForConnector')->willReturn([]);
        $emailRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$processed): mixed {
            $key = $criteria['importKey'] ?? null;
            if (is_string($key)) {
                $processed[] = $key;
            }

            return new \stdClass();
        });

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::never())->method('ensureSystemFolder');

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );

        $connectorId = (string) $connector->getId();
        self::assertSame(['imported' => 0, 'skipped' => 2, 'errors' => 0], $fetcher->fetch($connector, self::config(['pop3Security' => 'tls']), self::request(2, false, true)));
        self::assertSame([$connectorId.':uid-1', $connectorId.':uid-2'], $processed);
    }

    public function testFetchForConnectorProcessesAllOldestFirstWithoutLimit(): void
    {
        $connector = $this->createConnector();
        $processed = [];

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-1', 2 => 'uid-2']);

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createMock(EmailRepository::class);
        $emailRepository->method('importKeysForConnector')->willReturn([]);
        $emailRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$processed): mixed {
            $key = $criteria['importKey'] ?? null;
            if (is_string($key)) {
                $processed[] = $key;
            }

            return new \stdClass();
        });

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::never())->method('ensureSystemFolder');

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );

        $connectorId = (string) $connector->getId();
        self::assertSame(['imported' => 0, 'skipped' => 2, 'errors' => 0], $fetcher->fetch($connector, self::config(), self::request(null, false, true)));
        self::assertSame([$connectorId.':uid-1', $connectorId.':uid-2'], $processed);
    }

    public function testFetchForConnectorContinuesWhenOpenEntityManagerReportsUniqueConstraint(): void
    {
        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $account->setInboxFolder((new Folder())->setName('Postfach'));
        $connector = $this->createConnector($account);

        $storage = $this->createStub(Pop3Storage::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-1']);
        $storage->method('getMessage')->willReturn($this->createMessageStub());

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createStub(EmailRepository::class);
        $emailRepository->method('importKeysForConnector')->willReturn([]);
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
            $this->createRoutingService($account),
            $this->createStub(ConnectorConfigRepository::class),
        );

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 0], $fetcher->fetch($connector, self::config(), self::request()));
    }

    public function testFetchForConnectorCountsErrorWhenClosedEntityManagerReportsUniqueConstraint(): void
    {
        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $account->setInboxFolder((new Folder())->setName('Postfach'));
        $connector = $this->createConnector($account);

        $storage = $this->createStub(Pop3Storage::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-1']);
        $storage->method('getMessage')->willReturn($this->createMessageStub());

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createStub(EmailRepository::class);
        $emailRepository->method('importKeysForConnector')->willReturn([]);
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
            $this->createRoutingService($account),
            $this->createStub(ConnectorConfigRepository::class),
        );

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 1], $fetcher->fetch($connector, self::config(), self::request()));
    }

    public function testFetchForConnectorDeletesImportedMessagesAndUsesNewestOrderWithLimit(): void
    {
        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $account->setInboxFolder((new Folder())->setName('Postfach'));
        $connector = $this->createConnector($account);
        $processed = [];

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-1', 2 => 'uid-2', 3 => 'uid-3']);
        $storage->method('getMessage')->willReturn($this->createMessageStub());
        $storage->expects(self::exactly(2))->method('removeMessage')->with(self::greaterThan(0));

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createMock(EmailRepository::class);
        $emailRepository->method('importKeysForConnector')->willReturn([]);
        $emailRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$processed): mixed {
            $key = $criteria['importKey'] ?? null;
            if (is_string($key)) {
                $processed[] = $key;
            }

            return null;
        });

        $addressRepository = $this->createStub(EntityRepository::class);
        $addressRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Address::class)->willReturn($addressRepository);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::exactly(2))->method('flush');
        $entityManager->method('isOpen')->willReturn(true);

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::exactly(4))->method('ensureSystemFolder');
        $folderManager->expects(self::exactly(2))->method('syncEmailSystemFolders');

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
            $this->createRoutingService($account),
            $this->createStub(ConnectorConfigRepository::class),
        );

        $connectorId = (string) $connector->getId();
        self::assertSame(['imported' => 2, 'skipped' => 0, 'errors' => 0], $fetcher->fetch($connector, self::config(), self::request(2, true)));
        self::assertSame([$connectorId.':uid-3', $connectorId.':uid-2'], $processed);
    }

    public function testFetchForConnectorStopsAfterErrorWhenEntityManagerCloses(): void
    {
        $connector = $this->createConnector();
        $processed = [];

        $storage = $this->createStub(Pop3Storage::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-1', 2 => 'uid-2']);
        $storage->method('getMessage')->willThrowException(new \RuntimeException('broken message'));
        $storage->method('getRawHeader')->willThrowException(new \RuntimeException('no api'));
        $storage->method('getRawContent')->willThrowException(new \RuntimeException('no api'));

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);

        $emailRepository = $this->createMock(EmailRepository::class);
        $emailRepository->method('importKeysForConnector')->willReturn([]);
        $emailRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$processed): mixed {
            $key = $criteria['importKey'] ?? null;
            if (is_string($key)) {
                $processed[] = $key;
            }

            return null;
        });

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::never())->method('ensureSystemFolder');

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $emailRepository,
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $factory,
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );

        $connectorId = (string) $connector->getId();
        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 1], $fetcher->fetch($connector, self::config(), self::request()));
        self::assertSame([$connectorId.':uid-2'], $processed);
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

    private function createFetcher(): Pop3Fetcher
    {
        return new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $this->createStub(Pop3StorageFactory::class),
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );
    }


    /**
     * @return array<string, mixed>
     */
    private static function partialConfig(): array
    {
        return ['pop3Host' => 'mail.example.com', 'pop3Username' => 'user'];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function config(array $overrides = []): array
    {
        return array_merge([
            'pop3Host' => 'mail.example.com',
            'pop3Port' => 110,
            'pop3Username' => 'user',
            'pop3Password' => 'pass',
            'pop3Security' => 'ssl',
            'pop3DeleteAfterFetch' => true,
        ], $overrides);
    }

    private static function request(?int $limit = null, ?bool $deleteAfter = null, bool $oldestFirst = false): ConnectorFetchRequest
    {
        $request = new ConnectorFetchRequest();
        $request->limit = $limit;
        $request->deleteAfter = $deleteAfter;
        $request->oldestFirst = $oldestFirst;

        return $request;
    }
    public function testFetchUpdatesServerTotalInStoredConfig(): void
    {
        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $account->setInboxFolder((new Folder())->setName('Postfach'));
        $connector = $this->createConnector($account);

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-1']);
        $storage->expects(self::never())->method('removeMessage');
        $storage->expects(self::once())->method('close');

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createMock(EmailRepository::class);
        $emailRepository->method('importKeysForConnector')->willReturn([]);
        $emailRepository->method('findOneBy')->willReturn(new \stdClass());
        $emailRepository->method('count')->with(['connector' => $connector])->willReturn(5);

        $configEntity = new \AvalynxOffice\Entity\ConnectorConfig();
        $configEntity->setConnector($connector);
        $configEntity->setPluginKey('pop3');
        $configRepository = $this->createStub(ConnectorConfigRepository::class);
        $configRepository->method('findOneBy')->willReturn($configEntity);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $emailRepository,
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $factory,
            $this->createStub(MailRoutingService::class),
            $configRepository,
        );

        self::assertSame(['imported' => 0, 'skipped' => 1, 'errors' => 0], $fetcher->fetch($connector, self::config(), self::request()));

        // delete-after run: everything not deleted is already local (5) plus the one skipped on the server.
        self::assertSame(6, $configEntity->getConfig()['server_total']);
    }

    public function testFetchSwallowsServerTotalUpdateFailures(): void
    {
        $connector = $this->createConnector();

        $storage = $this->createMock(Pop3Storage::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-1']);
        $storage->expects(self::once())->method('close');

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $emailRepository = $this->createStub(EmailRepository::class);
        $emailRepository->method('importKeysForConnector')->willReturn([]);
        $emailRepository->method('findOneBy')->willReturn(new \stdClass());

        $configRepository = $this->createStub(ConnectorConfigRepository::class);
        $configRepository->method('findOneBy')->willThrowException(new \RuntimeException('db kaputt'));

        $messages = [];
        $output = $this->createStub(\Symfony\Component\Console\Output\OutputInterface::class);
        $output->method('writeln')->willReturnCallback(static function (iterable|string $message) use (&$messages): void {
            foreach ((array) $message as $line) {
                $messages[] = $line;
            }
        });

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $emailRepository,
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $factory,
            $this->createStub(MailRoutingService::class),
            $configRepository,
        );
        $fetcher->setOutput($output);

        self::assertSame(['imported' => 0, 'skipped' => 1, 'errors' => 0], $fetcher->fetch($connector, self::config(), self::request()));
        self::assertStringContainsString('Could not update the fetch status', implode("\n", array_map(static fn ($message): string => (string) $message, $messages)));
    }

    private function createConnector(?Account $defaultAccount = null): Connector
    {
        $connector = (new Connector())
            ->setName('Support')
            ->setDomain('example.com');
        $connector->setDefaultAccount($defaultAccount);

        return $connector;
    }

    private function createRoutingService(?Account $account): MailRoutingService
    {
        $routingService = $this->createStub(MailRoutingService::class);
        $routingService
            ->method('resolveTarget')
            ->willReturnCallback(static fn (): ?array => null !== $account ? ['account' => $account, 'alias' => null] : null);

        return $routingService;
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

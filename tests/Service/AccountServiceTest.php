<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAlias;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Entity\UserAccount;
use AvalynxOffice\Entity\FolderAccount;
use AvalynxOffice\Repository\AccountAliasRepository;
use AvalynxOffice\Repository\ConnectorRepository;
use AvalynxOffice\Service\AccountService;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailRoutingService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class AccountServiceTest extends TestCase
{
    private AccountService $service;

    private EntityManagerInterface $entityManager;

    private FolderManager $folderManager;

    private ConnectorRepository $connectorRepository;

    private AccountAliasRepository $aliasRepository;

    private MailRoutingService $mailRoutingService;

    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->folderManager = $this->createStub(FolderManager::class);
        $this->connectorRepository = $this->createStub(ConnectorRepository::class);
        $this->aliasRepository = $this->createStub(AccountAliasRepository::class);
        $this->mailRoutingService = $this->createStub(MailRoutingService::class);

        $this->service = new AccountService(
            $this->entityManager,
            $this->folderManager,
            $this->connectorRepository,
            $this->aliasRepository,
            $this->mailRoutingService,
        );
    }

    private function connectorWithFind(): Connector
    {
        $connector = (new Connector())
            ->setName('Example')
            ->setDomain('example.com');
        $this->connectorRepository
            ->method('find')
            ->willReturnCallback(static fn (string $id): ?Connector => $id === (string) $connector->getId() ? $connector : null);

        return $connector;
    }

    public function testNormalizeAliasesTrimsAndDeduplicatesValues(): void
    {
        $values = $this->service->normalizeAliases(json_encode([
            ['connectorId' => ' c1 ', 'localPart' => ' Info '],
            ['connectorId' => 'c1', 'localPart' => 'info'],
            ['connectorId' => 'c1', 'localPart' => ''],
            ['localPart' => 'orphan'],
        ]));

        $this->assertSame([
            ['connectorId' => 'c1', 'localPart' => 'info'],
        ], $values);
    }

    public function testFormatMethodsExposeAliasesAndFoldersWithFunctions(): void
    {
        $connector = (new Connector())
            ->setName('Example')
            ->setDomain('example.com');
        $connectorB = (new Connector())
            ->setName('Other')
            ->setDomain('other.example');

        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $account->addAlias((new AccountAlias())->setConnector($connector)->setLocalPart('help'));
        $account->addAlias((new AccountAlias())->setConnector($connectorB)->setLocalPart('info'));
        $account->addFolder((new Folder())->setName(FolderManager::FOLDER_MAILBOX)->setFunctions([FolderFunction::MAILBOX]), -100);
        $account->addFolder((new Folder())->setName('Archive'), 12);

        $this->assertSame(json_encode([
            ['connectorId' => (string) $connector->getId(), 'localPart' => 'help'],
            ['connectorId' => (string) $connectorB->getId(), 'localPart' => 'info'],
        ]), $this->service->formatAliases($account));
        $this->assertSame(json_encode([
            ['functions' => [FolderFunction::MAILBOX->value], 'name' => FolderManager::FOLDER_MAILBOX, 'sortOrder' => -100],
            ['functions' => [], 'name' => 'Archive', 'sortOrder' => 12],
        ]), $this->service->formatFolders($account));
    }

    public function testFormatFoldersIgnoresLinksWithoutFolders(): void
    {
        $account = new Account();
        $account->addFolder((new Folder())->setName('Archive'), 1);
        $account->addFolder((new Folder())->setName('Keep'), 2);
        $this->assertSame(json_encode([
            ['functions' => [], 'name' => 'Archive', 'sortOrder' => 1],
            ['functions' => [], 'name' => 'Keep', 'sortOrder' => 2],
        ]), $this->service->formatFolders($account));
    }

    public function testFindInvalidAliasesReturnsOnlyInvalidValues(): void
    {
        $connector = $this->connectorWithFind();
        $knownId = (string) $connector->getId();

        $invalid = $this->service->findInvalidAliases([
            ['connectorId' => $knownId, 'localPart' => 'valid'],
            ['connectorId' => $knownId, 'localPart' => 'with@at'],
            ['connectorId' => $knownId, 'localPart' => 'with space'],
            ['connectorId' => $knownId, 'localPart' => ''],
            ['connectorId' => 'unknown', 'localPart' => 'orphan'],
        ]);

        $this->assertSame(['with@at', 'with space', '(leer)', 'orphan'], $invalid);
    }

    public function testFindConflictingAliasesDetectsForeignAndInnerDuplicates(): void
    {
        $connector = $this->connectorWithFind();

        $account = (new Account())->setName('Support')->setEmail('support@example.com');
        $foreignAccount = (new Account())->setName('Info')->setEmail('info@example.com');
        $foreignAlias = (new AccountAlias())->setAccount($foreignAccount)->setConnector($connector)->setLocalPart('info');
        $ownAlias = (new AccountAlias())->setAccount($account)->setConnector($connector)->setLocalPart('help');

        $this->aliasRepository
            ->method('findOneBy')
            ->willReturnCallback(static fn (array $criteria): ?AccountAlias => 'info' === $criteria['localPart'] ? $foreignAlias : ('help' === $criteria['localPart'] ? $ownAlias : null));

        $knownId = (string) $connector->getId();
        $conflicts = $this->service->findConflictingAliases($account, [
            ['connectorId' => $knownId, 'localPart' => 'info'],
            ['connectorId' => $knownId, 'localPart' => 'help'],
            ['connectorId' => $knownId, 'localPart' => 'free'],
            ['connectorId' => 'unknown', 'localPart' => 'x'],
        ]);

        $this->assertSame(['info@example.com'], $conflicts);
    }

    public function testNormalizeFoldersKeepsSortOrderMetadata(): void
    {
        $values = $this->service->normalizeFolders(json_encode([
            ['name' => ' Archive ', 'sortOrder' => '7', 'functions' => ['none']],
            ['name' => 'Inbox', 'sortOrder' => 3, 'functions' => ['inbox']],
            ['name' => '', 'sortOrder' => 9, 'functions' => []],
            ['name' => 'NoSort', 'functions' => ['mailbox']],
        ]));

        $this->assertSame([
            ['functions' => [], 'name' => 'Archive', 'sortOrder' => 7],
            ['functions' => ['inbox'], 'name' => 'Inbox', 'sortOrder' => 3],
            ['functions' => ['mailbox'], 'name' => 'NoSort', 'sortOrder' => 3],
        ], $values);
    }

    public function testNormalizeMethodsReturnEmptyArrayForBlankOrInvalidPayloads(): void
    {
        $this->assertSame([], $this->service->normalizeAliases(null));
        $this->assertSame([], $this->service->normalizeAliases(''));
        $this->assertSame([], $this->service->normalizeAliases('not json'));
        $this->assertSame([], $this->service->normalizeAliases('42'));
        $this->assertSame([], $this->service->normalizeFolders(null));
        $this->assertSame([], $this->service->normalizeFolders('[]'));
    }

    public function testNormalizeMethodsSkipInvalidItemsAndUseFallbacks(): void
    {
        $aliases = $this->service->normalizeAliases(json_encode([
            'string-item',
            ['connectorId' => 42, 'localPart' => 7],
            ['connectorId' => 'c1', 'localPart' => 'UPPER'],
        ]));

        $this->assertSame([
            ['connectorId' => '42', 'localPart' => '7'],
            ['connectorId' => 'c1', 'localPart' => 'upper'],
        ], $aliases);

        $folders = $this->service->normalizeFolders(json_encode([
            'string-item',
            ['name' => 42, 'sortOrder' => null, 'functions' => 'not-an-array'],
            ['name' => 'Keep', 'sortOrder' => 'abc', 'functions' => ['inbox', 42, 'none']],
        ]));

        $this->assertSame([
            ['functions' => [], 'name' => '42', 'sortOrder' => 1],
            ['functions' => [], 'name' => 'Keep', 'sortOrder' => 2],
        ], $folders);
    }

    public function testFindNonRemovableFoldersFlagsFoldersWithEmails(): void
    {
        $account = new Account();
        $account->addFolder($this->createFolderWithEmails(FolderManager::FOLDER_MAILBOX, [FolderFunction::MAILBOX]), -100);
        $account->addFolder((new Folder())->setName('Archive'), 0);
        $account->addFolder($this->createFolderWithEmails('Needs Review'), 1);

        $blocked = $this->service->findNonRemovableFolders($account, [
            ['functions' => [FolderFunction::MAILBOX->value], 'name' => 'Postkorb', 'sortOrder' => -100],
            ['functions' => [], 'name' => 'Archive', 'sortOrder' => 0],
        ]);

        $this->assertSame(['Needs Review'], $blocked);
    }

    public function testFindDuplicateAndMissingSystemFolderFunctions(): void
    {
        $folders = [
            ['functions' => ['inbox'], 'name' => 'Posteingang', 'sortOrder' => 0],
            ['functions' => ['inbox'], 'name' => 'Doppelt', 'sortOrder' => 1],
        ];

        $this->assertSame(
            ['folder.function.inbox'],
            $this->service->findDuplicateFolderFunctions($folders),
        );

        $missing = $this->service->findMissingSystemFolderFunctions([
            ['functions' => ['inbox'], 'name' => 'Posteingang', 'sortOrder' => 0],
        ]);

        $this->assertSame(
            [
                'folder.function.mailbox',
                'folder.function.outbox',
                'folder.function.scheduled',
                'folder.function.drafts',
                'folder.function.trash',
            ],
            $missing,
        );
    }

    public function testFindDuplicateAndMissingSystemFolderFunctionsIgnoreNoneSentinel(): void
    {
        $folders = [
            ['functions' => ['none'], 'name' => 'Archive', 'sortOrder' => 0],
            ['functions' => ['none'], 'name' => 'Archive 2', 'sortOrder' => 1],
        ];

        $this->assertSame([], $this->service->findDuplicateFolderFunctions($folders));
    }

    public function testFindNonRemovableFoldersIgnoresLinksWithoutFolders(): void
    {
        $account = new Account();
        $account->addFolder((new Folder())->setName('Postfach')->setFunctions(['mailbox']), 0);

        $this->assertSame([], $this->service->findNonRemovableFolders($account, [
            ['functions' => ['mailbox'], 'name' => 'Postfach', 'sortOrder' => 0],
        ]));
    }

    private function serviceWithMockedEntityManager(EntityManagerInterface $entityManager): AccountService
    {
        return new AccountService(
            $entityManager,
            $this->folderManager,
            $this->connectorRepository,
            $this->aliasRepository,
            $this->mailRoutingService,
        );
    }

    public function testSavePersistsAccountAliasesUsersAndFolders(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $connector = $this->connectorWithFind();
        $knownId = (string) $connector->getId();

        $account = (new Account())->setName('Support')->setEmail('support@example.com');
        $user = (new User())->setEmail('user@example.com');
        $removedUser = (new User())->setEmail('gone@example.com');
        $account->addUser($removedUser);

        $entityManager->expects(self::atLeastOnce())->method('persist')->willReturnCallback(
            static function (object $entity) use ($account): void {
                if ($entity instanceof AccountAlias) {
                    return;
                }
                self::assertSame($account, $entity);
            },
        );
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $this->folderManager->method('ensureDefaultFolders');
        $this->folderManager->method('getFolderByFunction')->willReturn((new Folder())->setName('Postfach'));
        $this->folderManager->method('ensureFolder')->willReturn((new Folder())->setName('Postfach'));

        $this->serviceWithMockedEntityManager($entityManager)->save(
            $account,
            [['connectorId' => $knownId, 'localPart' => 'help']],
            [],
            [$user],
            true,
        );

        $aliases = $account->getAliases();
        self::assertCount(1, $aliases);
        self::assertSame('help', $aliases->first()?->getLocalPart());
        self::assertSame([$user], $account->getUsers()->toArray());
    }

    public function testSaveMovesForeignAliasAndTriggersReroute(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $connector = $this->connectorWithFind();
        $knownId = (string) $connector->getId();

        $account = (new Account())->setName('Support')->setEmail('support@example.com');
        $foreignAccount = (new Account())->setName('Info')->setEmail('info@example.com');
        $foreignAlias = (new AccountAlias())->setAccount($foreignAccount)->setConnector($connector)->setLocalPart('info');
        $foreignAccount->addAlias($foreignAlias);

        $this->aliasRepository
            ->method('findOneBy')
            ->willReturn($foreignAlias);

        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $this->folderManager->method('ensureDefaultFolders');
        $this->folderManager->method('getFolderByFunction')->willReturn((new Folder())->setName('Postfach'));
        $this->folderManager->method('ensureFolder')->willReturn((new Folder())->setName('Postfach'));

        $this->mailRoutingService = $this->createMock(MailRoutingService::class);
        $this->mailRoutingService
            ->expects(self::once())
            ->method('rerouteLocalPart')
            ->with($connector, 'info', $account);

        $this->service = new AccountService(
            $this->entityManager,
            $this->folderManager,
            $this->connectorRepository,
            $this->aliasRepository,
            $this->mailRoutingService,
        );

        $this->serviceWithMockedEntityManager($entityManager)->save($account, [['connectorId' => $knownId, 'localPart' => 'info']], []);

        self::assertSame($account, $foreignAlias->getAccount());
        self::assertCount(0, $foreignAccount->getAliases());
    }

    public function testSaveRemovesDroppedAliases(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $connector = $this->connectorWithFind();
        $account = (new Account())->setName('Support')->setEmail('support@example.com');
        $obsolete = (new AccountAlias())->setAccount($account)->setConnector($connector)->setLocalPart('old');
        $account->addAlias($obsolete);

        $entityManager->expects(self::once())->method('remove')->with($obsolete);
        $entityManager->expects(self::atLeastOnce())->method('flush');

        $this->folderManager->method('ensureDefaultFolders');
        $this->folderManager->method('getFolderByFunction')->willReturn((new Folder())->setName('Postfach'));
        $this->folderManager->method('ensureFolder')->willReturn((new Folder())->setName('Postfach'));

        $this->serviceWithMockedEntityManager($entityManager)->save($account, [], []);

        self::assertCount(0, $account->getAliases());
    }

    public function testDeleteRemovesAccount(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $account = (new Account())->setName('Support')->setEmail('support@example.com');

        $entityManager->expects(self::once())->method('remove')->with($account);
        $entityManager->expects(self::once())->method('flush');

        $this->serviceWithMockedEntityManager($entityManager)->delete($account);
    }

    public function testSaveUpdatesExistingAliasAndRemovesObsoleteFolders(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $connector = $this->connectorWithFind();
        $knownId = (string) $connector->getId();

        $account = (new Account())->setName('Support')->setEmail('support@example.com');
        $existingAlias = (new AccountAlias())->setAccount($account)->setConnector($connector)->setLocalPart('help');
        $account->addAlias($existingAlias);

        $folder = (new Folder())->setName('Old');
        $account->addFolder($folder, 5);

        $this->aliasRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?AccountAlias => 'help' === $criteria['localPart'] ? $existingAlias : null,
        );

        $this->folderManager->method('ensureDefaultFolders');
        $this->folderManager->method('getFolderByFunction')->willReturn((new Folder())->setName('Postfach'));
        $this->folderManager->method('ensureFolder')->willReturn((new Folder())->setName('Postfach'));

        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::once())->method('remove')->with(self::callback(
            static fn (object $candidate): bool => true
        ));

        $this->serviceWithMockedEntityManager($entityManager)->save(
            $account,
            [['connectorId' => $knownId, 'localPart' => 'help'], ['connectorId' => $knownId, 'localPart' => 'extra']],
            [],
        );

        self::assertCount(2, $account->getAliases());
    }

    public function testSaveSkipsInvalidAliasAndFolderLinksWhileMatchingByFunction(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $account = (new Account())->setName('Support')->setEmail('support@example.com');
        $folder = (new Folder())->setName('Eingang')->setFunctions(['inbox']);
        $account->addFolder($folder, 1);

        $this->connectorRepository->method('find')->willReturn(null);

        $this->folderManager->method('ensureDefaultFolders');
        $this->folderManager->method('getFolderByFunction')->willReturn((new Folder())->setName('Postfach'));
        $this->folderManager->method('ensureFolder')->willReturn(new Folder());

        $this->serviceWithMockedEntityManager($entityManager)->save(
            $account,
            [['connectorId' => 'missing', 'localPart' => 'help']],
            [['functions' => ['inbox'], 'name' => 'Eingang', 'sortOrder' => 1]],
        );

        self::assertCount(0, $account->getAliases());
        self::assertSame('Eingang', $folder->getName());
    }

    private function createFolderWithEmails(string $name, array $functions = []): Folder
    {
        $emailFolders = new ArrayCollection([new EmailFolder()]);

        return (new class($emailFolders) extends Folder {
            public function __construct(
                private readonly Collection $testEmailFolders,
            ) {
                parent::__construct();
            }

            #[\Override]
            public function getEmailFolders(): Collection
            {
                return $this->testEmailFolders;
            }
        })->setName($name)->setFunctions($functions);
    }

    public function testFormatHelpersSkipMalformedLinkEntries(): void
    {
        $account = new Account();
        $account->getAliasLinks()->add(new \stdClass());

        $folderAccount = new FolderAccount();
        $account->getFolderLinks()->add($folderAccount);

        self::assertSame('[]', $this->service->formatAliases($account));
        self::assertSame('[]', $this->service->formatFolders($account));
    }

    public function testFindConflictingAliasesSkipsInPayloadDuplicates(): void
    {
        $connector = $this->connectorWithFind();
        $account = (new Account())->setName('Support')->setEmail('support@example.com');

        $this->aliasRepository->method('findOneBy')->willReturn(null);

        $knownId = (string) $connector->getId();
        $conflicts = $this->service->findConflictingAliases($account, [
            ['connectorId' => $knownId, 'localPart' => 'info'],
            ['connectorId' => $knownId, 'localPart' => 'info'],
        ]);

        self::assertSame([], $conflicts);
    }

    public function testSyncAliasesSkipsAliasAlreadyOwnedByAccount(): void
    {
        $connector = $this->connectorWithFind();
        $knownId = (string) $connector->getId();

        $account = (new Account())->setName('Support')->setEmail('support@example.com');
        $ownedAlias = (new AccountAlias())->setAccount($account)->setConnector($connector)->setLocalPart('own');
        // Intentionally NOT attached to the account: the alias exists in the
        // database and already points at this account.
        $this->aliasRepository->method('findOneBy')->willReturn($ownedAlias);

        $this->folderManager->method('ensureDefaultFolders');
        $this->folderManager->method('getFolderByFunction')->willReturn((new Folder())->setName('Postfach'));
        $this->folderManager->method('ensureFolder')->willReturn((new Folder())->setName('Postfach'));

        $this->service->save($account, [['connectorId' => $knownId, 'localPart' => 'own']]);

        self::assertSame([], $account->getAliases()->toArray());
    }

    public function testSyncFoldersCallsEnsureFolderForUnmatchedFolders(): void
    {
        $account = (new Account())->setName('Support')->setEmail('support@example.com');

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->method('ensureDefaultFolders');
        $folderManager->method('getFolderByFunction')->willReturn((new Folder())->setName('Postfach'));
        $folderManager->expects(self::atLeastOnce())
            ->method('ensureFolder')
            ->willReturnCallback(static function (Account $a, string $name): Folder {
                if ('Archive' === $name) {
                    return (new Folder())->setName('Archive');
                }

                return (new Folder())->setName($name);
            });

        $service = new AccountService(
            $this->createStub(EntityManagerInterface::class),
            $folderManager,
            $this->connectorRepository,
            $this->aliasRepository,
            $this->mailRoutingService,
        );

        $service->save($account, [], [['functions' => [], 'name' => 'Archive', 'sortOrder' => 1]]);
    }

    public function testFindNonRemovableFoldersSkipsLinksWithoutFolder(): void
    {
        $account = new Account();
        $account->getFolderLinks()->add(new FolderAccount());

        $this->assertSame([], $this->service->findNonRemovableFolders($account, []));
    }

    public function testFindMissingSystemFolderFunctionsIgnoreNoneSentinel(): void
    {
        $this->assertSame(
            ['folder.function.mailbox', 'folder.function.inbox', 'folder.function.outbox', 'folder.function.scheduled', 'folder.function.drafts', 'folder.function.trash'],
            $this->service->findMissingSystemFolderFunctions([
                ['functions' => ['none'], 'name' => 'Archive', 'sortOrder' => 0],
            ]),
        );
    }

    public function testFindConnectorReturnsNullWhenRepositoryThrows(): void
    {
        $this->connectorRepository->method('find')->willThrowException(new \RuntimeException('db kaputt'));

        $this->assertSame(['orphan'], $this->service->findInvalidAliases([
            ['connectorId' => '01a16b7b-0000-736d-acf5-41eb5d3bbaad', 'localPart' => 'orphan'],
        ]));
    }

    public function testSyncUsersKeepsStillAssignedUsersAndSkipsMalformedLinks(): void
    {
        $account = (new Account())->setName('Support')->setEmail('support@example.com');
        $kept = (new User())->setEmail('kept@example.com');
        $account->addUser($kept);
        $malformed = new UserAccount();
        $account->getUserLinks()->add($malformed);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $this->folderManager->method('ensureDefaultFolders');
        $this->folderManager->method('getFolderByFunction')->willReturn((new Folder())->setName('Postfach'));
        $this->folderManager->method('ensureFolder')->willReturn((new Folder())->setName('Postfach'));

        $service = new AccountService(
            $entityManager,
            $this->folderManager,
            $this->connectorRepository,
            $this->aliasRepository,
            $this->mailRoutingService,
        );

        $service->save($account, [], [], [$kept]);

        self::assertSame([$kept], $account->getUsers()->toArray());
    }

    public function testSyncAliasesKeepsOwnAliasesAndSkipsMalformedLinks(): void
    {
        $connector = $this->connectorWithFind();
        $knownId = (string) $connector->getId();

        $account = (new Account())->setName('Support')->setEmail('support@example.com');
        $own = (new AccountAlias())->setAccount($account)->setConnector($connector)->setLocalPart('own');
        $account->addAlias($own);
        $account->getAliasLinks()->add(new \stdClass());

        $this->folderManager->method('ensureDefaultFolders');
        $this->folderManager->method('getFolderByFunction')->willReturn((new Folder())->setName('Postfach'));
        $this->folderManager->method('ensureFolder')->willReturn((new Folder())->setName('Postfach'));

        $this->service->save($account, [['connectorId' => $knownId, 'localPart' => 'own']]);

        self::assertCount(1, $account->getAliases()->filter(
            static fn (object $entry): bool => $entry instanceof AccountAlias,
        ));
    }

    public function testSyncFoldersMatchesByNameAndSkipsMalformedLinks(): void
    {
        $account = (new Account())->setName('Support')->setEmail('support@example.com');
        $archive = (new Folder())->setName('Archive');
        $account->addFolder($archive, 1);
        $account->getFolderLinks()->add(new FolderAccount());

        $this->folderManager->method('ensureDefaultFolders');
        $this->folderManager->method('getFolderByFunction')->willReturn((new Folder())->setName('Postfach'));
        $this->folderManager->method('ensureFolder')->willReturn((new Folder())->setName('Postfach'));

        $this->service->save($account, [], [['functions' => [], 'name' => 'Archive', 'sortOrder' => 1]]);

        self::assertSame('Archive', $archive->getName());
    }

    public function testSaveSkipsFolderDefinitionsWithoutName(): void
    {
        $account = (new Account())->setName('Support')->setEmail('support@example.com');

        $this->folderManager->method('ensureDefaultFolders');
        $this->folderManager->method('getFolderByFunction')->willReturn((new Folder())->setName('Postfach'));
        $this->folderManager->method('ensureFolder')->willReturn((new Folder())->setName('Postfach'));

        $this->service->save($account, [], [['functions' => [], 'name' => '', 'sortOrder' => 0]]);

        self::assertCount(0, $account->getFolders());
    }

    public function testSyncFoldersMatchesFolderByFunctionAndRenamesIt(): void
    {
        $account = (new Account())->setName('Support')->setEmail('support@example.com');
        $folder = (new Folder())->setName('Alt')->setFunctions(['inbox']);
        $account->addFolder($folder, 1);

        $this->folderManager->method('ensureDefaultFolders');
        $this->folderManager->method('getFolderByFunction')->willReturn((new Folder())->setName('Postfach'));
        $this->folderManager->method('ensureFolder')->willReturn((new Folder())->setName('Postfach'));

        $this->service->save($account, [], [['functions' => ['inbox'], 'name' => 'Neu', 'sortOrder' => 1]]);

        self::assertSame('Neu', $folder->getName());
    }
}

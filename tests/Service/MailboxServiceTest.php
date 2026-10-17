<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Repository\AddressRepository;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\MailboxService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class MailboxServiceTest extends TestCase
{
    private MailboxService $service;

    protected function setUp(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $folderManager = $this->createStub(FolderManager::class);
        $addressRepository = $this->createStub(AddressRepository::class);

        $this->service = new MailboxService($entityManager, $folderManager, $addressRepository);
    }

    public function testNormalizeAliasesTrimsAndDeduplicatesValues(): void
    {
        $values = $this->service->normalizeAliases(json_encode([
            ['email' => ' alias@example.com ', 'name' => ' Alias One '],
            ['email' => 'ALIAS@example.com', 'name' => 'Alias Two'],
            ['email' => '', 'name' => 'Ignored'],
        ]));

        $this->assertSame([
            ['email' => 'alias@example.com', 'name' => 'Alias Two'],
        ], $values);
    }

    public function testFormatMethodsExposeAliasesAndFoldersWithFunctions(): void
    {
        $mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');
        $mailbox->addAddress((new Address())->setEmail('help@example.com')->setName('Helpdesk'));
        $mailbox->addAddress((new Address())->setEmail('info@example.com'));
        $mailbox->addFolder((new Folder())->setName(FolderManager::FOLDER_MAILBOX)->setFunctions([FolderFunction::MAILBOX]), -100);
        $mailbox->addFolder((new Folder())->setName('Archive'), 12);

        $this->assertSame(json_encode([
            ['email' => 'help@example.com', 'name' => 'Helpdesk'],
            ['email' => 'info@example.com', 'name' => null],
        ]), $this->service->formatAliases($mailbox));
        $this->assertSame(json_encode([
            ['functions' => ['mailbox'], 'name' => FolderManager::FOLDER_MAILBOX, 'sortOrder' => -100],
            ['functions' => [], 'name' => 'Archive', 'sortOrder' => 12],
        ]), $this->service->formatFolders($mailbox));
    }

    public function testFormatFoldersIgnoresLinksWithoutFolders(): void
    {
        $mailbox = new Mailbox();
        $folderLinks = new ArrayCollection([
            new class extends \AvalynxOffice\Entity\FolderMailbox {
                #[\Override]
                public function getFolder(): ?Folder
                {
                    return null;
                }
            },
        ]);
        $this->setMailboxProperty($mailbox, 'folderLinks', $folderLinks);

        $this->assertSame('[]', $this->service->formatFolders($mailbox));
    }

    public function testFindInvalidAliasesReturnsOnlyInvalidValues(): void
    {
        $invalid = $this->service->findInvalidAliases([
            ['email' => 'valid@example.com', 'name' => null],
            ['email' => 'not-an-email', 'name' => null],
            ['email' => 'still-wrong@', 'name' => 'Broken'],
        ]);

        $this->assertSame(['not-an-email', 'still-wrong@'], $invalid);
    }

    public function testNormalizeFoldersKeepsSortOrderMetadata(): void
    {
        $folders = $this->service->normalizeFolders(json_encode([
            ['name' => ' Archive ', 'sortOrder' => '10', 'functions' => ['outbox']],
            ['name' => 'archive', 'sortOrder' => 20, 'functions' => ['none', 'outbox', 'invalid']],
            ['name' => '', 'sortOrder' => 5],
        ]));

        $this->assertSame([
            ['functions' => [], 'name' => 'archive', 'sortOrder' => 20],
        ], $folders);
    }

    public function testNormalizeMethodsReturnEmptyArrayForBlankOrInvalidPayloads(): void
    {
        $this->assertSame([], $this->service->normalizeAliases(null));
        $this->assertSame([], $this->service->normalizeFolders('{"broken":'));
    }

    public function testNormalizeMethodsSkipInvalidItemsAndUseFallbacks(): void
    {
        $this->assertSame([
            ['email' => 'alias@example.com', 'name' => null],
        ], $this->service->normalizeAliases(json_encode([
            'skip-me',
            ['email' => 'alias@example.com', 'name' => []],
        ])));

        $this->assertSame([
            ['functions' => [], 'name' => 'Archive', 'sortOrder' => 1],
        ], $this->service->normalizeFolders(json_encode([
            'skip-me',
            ['name' => ' Archive ', 'sortOrder' => '', 'functions' => 'invalid'],
        ])));
    }

    public function testFindNonRemovableFoldersFlagsFoldersWithEmails(): void
    {
        $mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');
        $mailbox->addFolder($this->createFolder(FolderManager::FOLDER_MAILBOX, 0, [FolderFunction::MAILBOX]), -100);
        $mailbox->addFolder($this->createFolder('Archive'));
        $mailbox->addFolder($this->createFolder('Needs Review', 1));

        $blocked = $this->service->findNonRemovableFolders($mailbox, [
            ['functions' => [FolderFunction::MAILBOX->value], 'name' => 'Postkorb', 'sortOrder' => -100],
            ['functions' => [], 'name' => 'Archive', 'sortOrder' => 0],
        ]);

        $this->assertSame(['Needs Review'], $blocked);
    }

    public function testFindDuplicateAndMissingSystemFolderFunctions(): void
    {
        $folders = [
            ['functions' => [FolderFunction::MAILBOX->value], 'name' => 'Postfach', 'sortOrder' => -100],
            ['functions' => [FolderFunction::MAILBOX->value], 'name' => 'Alle Eingaenge', 'sortOrder' => -50],
        ];

        $this->assertSame(['folder.function.mailbox'], $this->service->findDuplicateFolderFunctions($folders));
        $this->assertSame(
            ['folder.function.inbox', 'folder.function.outbox', 'folder.function.drafts'],
            $this->service->findMissingSystemFolderFunctions($folders),
        );
    }

    public function testFindDuplicateAndMissingSystemFolderFunctionsIgnoreNoneSentinel(): void
    {
        $folders = [
            ['functions' => [FolderFunction::NONE->value], 'name' => 'Neutral', 'sortOrder' => 0],
        ];

        $this->assertSame([], $this->service->findDuplicateFolderFunctions($folders));
        $this->assertSame(
            ['folder.function.mailbox', 'folder.function.inbox', 'folder.function.outbox', 'folder.function.drafts'],
            $this->service->findMissingSystemFolderFunctions($folders),
        );
    }

    public function testFindNonRemovableFoldersIgnoresLinksWithoutFolders(): void
    {
        $mailbox = new Mailbox();
        $this->setMailboxProperty($mailbox, 'folderLinks', new ArrayCollection([
            new class extends \AvalynxOffice\Entity\FolderMailbox {
                #[\Override]
                public function getFolder(): ?Folder
                {
                    return null;
                }
            },
        ]));

        $this->assertSame([], $this->service->findNonRemovableFolders($mailbox, []));
    }

    public function testSavePersistsMailboxAliasesAndFolders(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $folderManager = $this->createMock(FolderManager::class);
        $addressRepository = $this->createMock(AddressRepository::class);
        $service = new MailboxService($entityManager, $folderManager, $addressRepository);

        $mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');
        $inboxFolder = (new Folder())->setName(FolderManager::FOLDER_MAILBOX)->setFunctions([FolderFunction::MAILBOX]);

        $addressRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'alias@example.com'])
            ->willReturn(null);

        $folderManager
            ->expects($this->once())
            ->method('ensureDefaultFolders')
            ->with($mailbox);
        $folderManager
            ->expects($this->exactly(5))
            ->method('ensureFolder')
            ->with(
                $mailbox,
                $this->logicalOr(
                    $this->equalTo('Archive'),
                    $this->equalTo(FolderManager::FOLDER_MAILBOX),
                    $this->equalTo(FolderManager::FOLDER_INBOX),
                    $this->equalTo(FolderManager::FOLDER_OUTBOX),
                    $this->equalTo(FolderManager::FOLDER_DRAFTS),
                ),
                $this->isInt(),
                $this->isArray(),
            );
        $folderManager
            ->expects($this->once())
            ->method('getFolderByFunction')
            ->with($mailbox, FolderFunction::MAILBOX)
            ->willReturn($inboxFolder);

        $entityManager
            ->expects($this->exactly(3))
            ->method('persist')
            ->with($this->logicalOr(
                $this->identicalTo($mailbox),
                $this->isInstanceOf(Address::class),
            ));
        $entityManager
            ->expects($this->exactly(2))
            ->method('flush');

        $service->save(
            $mailbox,
            [
                ['email' => 'support@example.com', 'name' => 'Ignored mailbox'],
                ['email' => 'alias@example.com', 'name' => 'Alias'],
            ],
            [
                ['functions' => [], 'name' => 'Archive', 'sortOrder' => 50],
            ],
            true,
        );

        self::assertCount(1, $mailbox->getAddresses());
        self::assertSame('alias@example.com', $mailbox->getAddresses()->first()->getEmail());
        self::assertSame($inboxFolder, $mailbox->getInboxFolder());
    }

    public function testDeleteRemovesMailbox(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new MailboxService(
            $entityManager,
            $this->createStub(FolderManager::class),
            $this->createStub(AddressRepository::class),
        );
        $mailbox = new Mailbox();

        $entityManager
            ->expects($this->once())
            ->method('remove')
            ->with($mailbox);
        $entityManager
            ->expects($this->once())
            ->method('flush');

        $service->delete($mailbox);
    }

    public function testSaveUpdatesExistingAliasAndRemovesObsoleteFolders(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $folderManager = $this->createMock(FolderManager::class);
        $service = new MailboxService(
            $entityManager,
            $folderManager,
            $this->createStub(AddressRepository::class),
        );

        $mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');
        $alias = (new Address())->setEmail('alias@example.com')->setName('Old');
        $mailbox->addAddress($alias);
        $mailboxFolder = (new Folder())->setName(FolderManager::FOLDER_MAILBOX)->setFunctions([]);
        $obsoleteFolder = $this->createFolder('Obsolete');
        $mailbox->addFolder($mailboxFolder, 9);
        $mailbox->addFolder($obsoleteFolder, 20);

        $folderManager
            ->expects($this->once())
            ->method('ensureDefaultFolders')
            ->with($mailbox);
        $folderManager
            ->expects($this->exactly(3))
            ->method('ensureFolder');
        $folderManager
            ->expects($this->once())
            ->method('getFolderByFunction')
            ->with($mailbox, FolderFunction::MAILBOX)
            ->willReturn($mailboxFolder);

        $entityManager
            ->expects($this->exactly(3))
            ->method('persist')
            ->with($this->logicalOr(
                $this->identicalTo($mailboxFolder),
                $this->identicalTo($mailbox->getFolderLinks()->first()),
                $this->identicalTo($mailbox),
            ));
        $entityManager
            ->expects($this->once())
            ->method('remove')
            ->with($mailbox->getFolderLinks()->last());
        $entityManager
            ->expects($this->once())
            ->method('flush');

        $service->save(
            $mailbox,
            [
                ['email' => 'alias@example.com', 'name' => 'Updated'],
            ],
            [
                ['functions' => [FolderFunction::MAILBOX->value], 'name' => FolderManager::FOLDER_MAILBOX, 'sortOrder' => -100],
            ],
        );

        self::assertSame('Updated', $alias->getName());
        self::assertSame(FolderManager::FOLDER_MAILBOX, $mailboxFolder->getName());
        self::assertSame([FolderFunction::MAILBOX->value], $mailboxFolder->getFunctions());
        self::assertCount(1, $mailbox->getFolderLinks());
        self::assertSame($mailboxFolder, $mailbox->getInboxFolder());
    }

    public function testSaveSkipsInvalidAliasAndFolderLinksWhileMatchingByFunction(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $folderManager = $this->createMock(FolderManager::class);
        $service = new MailboxService(
            $entityManager,
            $folderManager,
            $this->createStub(AddressRepository::class),
        );

        $addresses = new ArrayCollection([
            new \stdClass(),
            $existingAddress = (new Address())->setEmail('alias@example.com')->setName('Old'),
        ]);
        $mailbox = (new class($addresses) extends Mailbox {
            public function __construct(
                private readonly Collection $testAddresses,
            ) {
                parent::__construct();
            }

            #[\Override]
            public function getAddresses(): Collection
            {
                return $this->testAddresses;
            }
        })
            ->setName('Support')
            ->setEmail('support@example.com');
        $functionFolder = (new Folder())->setName('Old Mailbox')->setFunctions([FolderFunction::MAILBOX]);

        $invalidExistingLink = new class extends \AvalynxOffice\Entity\FolderMailbox {
            #[\Override]
            public function getFolder(): ?Folder
            {
                return null;
            }
        };
        $matchingLink = new class($functionFolder) extends \AvalynxOffice\Entity\FolderMailbox {
            private int $calls = 0;

            public function __construct(
                private readonly Folder $folder,
            ) {
                $this->setSortOrder(7);
            }

            #[\Override]
            public function getFolder(): ?Folder
            {
                ++$this->calls;

                return 1 === $this->calls ? $this->folder : null;
            }
        };
        $invalidRemainingLink = new class extends \AvalynxOffice\Entity\FolderMailbox {
            #[\Override]
            public function getFolder(): ?Folder
            {
                return null;
            }
        };

        $this->setMailboxProperty($mailbox, 'folderLinks', new ArrayCollection([
            $invalidExistingLink,
            $matchingLink,
            $invalidRemainingLink,
        ]));

        $folderManager->expects($this->once())->method('ensureDefaultFolders')->with($mailbox);
        $folderManager->expects($this->exactly(3))->method('ensureFolder');
        $folderManager->expects($this->once())
            ->method('getFolderByFunction')
            ->with($mailbox, FolderFunction::MAILBOX)
            ->willReturn(null);

        $entityManager->expects($this->once())
            ->method('persist')
            ->with($this->identicalTo($mailbox));
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->once())->method('flush');

        $service->save(
            $mailbox,
            [
                ['email' => 'support@example.com', 'name' => 'Ignored mailbox'],
            ],
            [
                ['functions' => [], 'name' => '   ', 'sortOrder' => 0],
                ['functions' => [FolderFunction::MAILBOX->value], 'name' => 'Mailbox Renamed', 'sortOrder' => -100],
            ],
        );

        $this->assertSame('Old', $existingAddress->getName());
        $this->assertSame('Old Mailbox', $functionFolder->getName());
        $this->assertSame([FolderFunction::MAILBOX->value], $functionFolder->getFunctions());
    }

    /**
     * @param array<int, FolderFunction|string> $functions
     */
    private function createFolder(string $name, int $emailCount = 0, array $functions = []): Folder
    {
        if (0 === $emailCount) {
            return (new Folder())->setName($name)->setFunctions($functions);
        }

        $emailFolders = new ArrayCollection(array_fill(0, $emailCount, new EmailFolder()));

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

    private function setMailboxProperty(Mailbox $mailbox, string $property, mixed $value): void
    {
        $reflectionProperty = new \ReflectionProperty(Mailbox::class, $property);
        $reflectionProperty->setValue($mailbox, $value);
    }
}

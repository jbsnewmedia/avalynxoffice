<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Mail;

use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\Pop3Fetcher;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('integration')]
class Pop3FetcherInboxTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $em;
    private Pop3Fetcher $fetcher;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);

        $this->fetcher = static::getContainer()->get(Pop3Fetcher::class);
    }

    public function testEnsureInboxForAccountCreatesPostfach(): void
    {
        $account = new Account();
        $account->setName('Test Account');
        $account->setEmail('test@example.com');
        $this->em->persist($account);
        $this->em->flush();

        // Reflection to access private method for testing
        $reflection = new \ReflectionClass(Pop3Fetcher::class);
        $method = $reflection->getMethod('ensureInboxForAccount');

        $method->invoke($this->fetcher, $account);

        $folderManager = static::getContainer()->get(FolderManager::class);

        /** @var Folder|null $folder */
        $folder = $folderManager->getFolderByFunction($account, FolderFunction::MAILBOX);

        $this->assertInstanceOf(Folder::class, $folder);
        $this->assertEquals('Postfach', $folder->getName());
        $this->assertNotNull($account->getInboxFolder());
        $this->assertEquals($folder->getId(), $account->getInboxFolder()->getId());

        // Call again should return the same folder
        $method->invoke($this->fetcher, $account);
        $folder2 = $folderManager->getFolderByFunction($account, FolderFunction::MAILBOX);
        $this->assertInstanceOf(Folder::class, $folder2);
        $this->assertEquals($folder->getId(), $folder2->getId());
    }

    public function testEnsureInboxForAccountUsesExistingInbox(): void
    {
        $account = new Account();
        $account->setName('Test Account');
        $account->setEmail('test@example.com');
        $this->em->persist($account);

        $existingInbox = new Folder();
        $existingInbox->setName('Postfach');
        $existingInbox->setFunctions([FolderFunction::MAILBOX]);
        $account->addFolder($existingInbox, FolderManager::defaultSortOrder(FolderFunction::MAILBOX));
        $this->em->persist($existingInbox);

        $account->setInboxFolder($existingInbox);
        $this->em->flush();

        $reflection = new \ReflectionClass(Pop3Fetcher::class);
        $method = $reflection->getMethod('ensureInboxForAccount');

        $method->invoke($this->fetcher, $account);
        $folder = static::getContainer()->get(FolderManager::class)->getFolderByFunction($account, FolderFunction::MAILBOX);

        $this->assertInstanceOf(Folder::class, $folder);
        $this->assertEquals($existingInbox->getId(), $folder->getId());
    }
}

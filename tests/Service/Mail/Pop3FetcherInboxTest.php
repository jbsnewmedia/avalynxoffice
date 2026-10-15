<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Enum\FolderFunction;
use App\Entity\Folder;
use App\Entity\Mailbox;
use App\Service\Mail\FolderManager;
use App\Service\Mail\Pop3Fetcher;
use App\Tests\Support\RefreshDatabaseTrait;
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

    public function testEnsureInboxForMailboxCreatesPostfach(): void
    {
        $mailbox = new Mailbox();
        $mailbox->setName('Test Mailbox');
        $mailbox->setEmail('test@example.com');
        $this->em->persist($mailbox);
        $this->em->flush();

        // Reflection to access private method for testing
        $reflection = new \ReflectionClass(Pop3Fetcher::class);
        $method = $reflection->getMethod('ensureInboxForMailbox');

        $method->invoke($this->fetcher, $mailbox);

        $folderManager = static::getContainer()->get(FolderManager::class);

        /** @var Folder|null $folder */
        $folder = $folderManager->getFolderByFunction($mailbox, FolderFunction::MAILBOX);

        $this->assertInstanceOf(Folder::class, $folder);
        $this->assertEquals('Postfach', $folder->getName());
        $this->assertNotNull($mailbox->getInboxFolder());
        $this->assertEquals($folder->getId(), $mailbox->getInboxFolder()->getId());

        // Call again should return the same folder
        $method->invoke($this->fetcher, $mailbox);
        $folder2 = $folderManager->getFolderByFunction($mailbox, FolderFunction::MAILBOX);
        $this->assertInstanceOf(Folder::class, $folder2);
        $this->assertEquals($folder->getId(), $folder2->getId());
    }

    public function testEnsureInboxForMailboxUsesExistingInbox(): void
    {
        $mailbox = new Mailbox();
        $mailbox->setName('Test Mailbox');
        $mailbox->setEmail('test@example.com');
        $this->em->persist($mailbox);

        $existingInbox = new Folder();
        $existingInbox->setName('Postfach');
        $existingInbox->setFunctions([FolderFunction::MAILBOX]);
        $mailbox->addFolder($existingInbox, FolderManager::defaultSortOrder(FolderFunction::MAILBOX));
        $this->em->persist($existingInbox);

        $mailbox->setInboxFolder($existingInbox);
        $this->em->flush();

        $reflection = new \ReflectionClass(Pop3Fetcher::class);
        $method = $reflection->getMethod('ensureInboxForMailbox');

        $method->invoke($this->fetcher, $mailbox);
        $folder = static::getContainer()->get(FolderManager::class)->getFolderByFunction($mailbox, FolderFunction::MAILBOX);

        $this->assertInstanceOf(Folder::class, $folder);
        $this->assertEquals($existingInbox->getId(), $folder->getId());
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Entity;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\FolderMailbox;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\MailboxConnector;
use AvalynxOffice\Entity\User;
use PHPUnit\Framework\TestCase;

class MailboxExtendedTest extends TestCase
{
    public function testPrimaryConnectorIsResolved(): void
    {
        $mailbox = new Mailbox();
        $fallback = (new MailboxConnector())
            ->setName('Fallback Connector')
            ->setEmail('fallback@example.com');
        $primary = (new MailboxConnector())
            ->setName('Primary Connector')
            ->setEmail('primary@example.com')
            ->setIsPrimary(true);

        $mailbox->addConnector($fallback);
        $mailbox->addConnector($primary);

        $this->assertSame($primary, $mailbox->getPrimaryConnector());
        $this->assertFalse($fallback->isPrimary());
    }

    public function testFirstConnectorBecomesPrimaryAutomatically(): void
    {
        $mailbox = new Mailbox();
        $connector = (new MailboxConnector())
            ->setName('Connector')
            ->setEmail('connector@example.com');

        $mailbox->addConnector($connector);

        $this->assertTrue($connector->isPrimary());
        $this->assertSame($connector, $mailbox->getPrimaryConnector());
    }

    public function testMailboxAddressDeduplicatesEntries(): void
    {
        $mailbox = new Mailbox();
        $address = (new Address())->setEmail('alias@example.com');

        $mailbox->addAddress($address);
        $mailbox->addAddress($address);

        $this->assertCount(1, $mailbox->getAddressLinks());
        $this->assertCount(1, $mailbox->getAddresses());
    }

    public function testInboxFolderRelationship(): void
    {
        $mailbox = new Mailbox();
        $inboxFolder = new Folder();
        $inboxFolder->setName('Postfach');

        $this->assertNull($mailbox->getInboxFolder());

        $mailbox->setInboxFolder($inboxFolder);
        $this->assertSame($inboxFolder, $mailbox->getInboxFolder());

        $mailbox->setInboxFolder(null);
        $this->assertNull($mailbox->getInboxFolder());
    }

    public function testFolderLinkManagement(): void
    {
        $mailbox = new Mailbox();
        $folder = new Folder();
        $folder->setName('Sent');

        $link = new FolderMailbox();
        $link->setMailbox($mailbox);
        $link->setFolder($folder);
        $link->setSortOrder(10);

        $mailbox->getFolderLinks()->add($link);

        $this->assertCount(1, $mailbox->getFolderLinks());
        $this->assertSame($link, $mailbox->getFolderLinks()[0]);
    }

    public function testGetFolders(): void
    {
        $mailbox = new Mailbox();
        $folder1 = new Folder();
        $folder1->setName('Inbox');
        $folder2 = new Folder();
        $folder2->setName('Sent');

        $link1 = new FolderMailbox();
        $link1->setMailbox($mailbox);
        $link1->setFolder($folder1);

        $link2 = new FolderMailbox();
        $link2->setMailbox($mailbox);
        $link2->setFolder($folder2);

        $mailbox->getFolderLinks()->add($link1);
        $mailbox->getFolderLinks()->add($link2);

        $folders = $mailbox->getFolders();
        $this->assertCount(2, $folders);
        $this->assertTrue($folders->contains($folder1));
        $this->assertTrue($folders->contains($folder2));
    }

    public function testAddUserMethod(): void
    {
        $mailbox = new Mailbox();
        $user = new User();
        $user->setEmail('user@example.com');

        $mailbox->addUser($user);

        $this->assertCount(1, $mailbox->getUserLinks());
        $this->assertTrue($mailbox->getUsers()->contains($user));
    }

    public function testAddUserPreventsDuplicates(): void
    {
        $mailbox = new Mailbox();
        $user = new User();
        $user->setEmail('user@example.com');

        $mailbox->addUser($user);
        $mailbox->addUser($user);

        $this->assertCount(1, $mailbox->getUserLinks());
        $this->assertCount(1, $mailbox->getUsers());
    }

    public function testRemoveUser(): void
    {
        $mailbox = new Mailbox();
        $user = new User();
        $user->setEmail('user@example.com');

        $mailbox->addUser($user);
        $this->assertCount(1, $mailbox->getUserLinks());

        $mailbox->removeUser($user);
        $this->assertCount(0, $mailbox->getUserLinks());
        $this->assertCount(0, $mailbox->getUsers());
    }

    public function testGetUsers(): void
    {
        $mailbox = new Mailbox();
        $user1 = new User();
        $user1->setEmail('user1@example.com');
        $user2 = new User();
        $user2->setEmail('user2@example.com');

        $mailbox->addUser($user1);
        $mailbox->addUser($user2);

        $users = $mailbox->getUsers();
        $this->assertCount(2, $users);
        $this->assertTrue($users->contains($user1));
        $this->assertTrue($users->contains($user2));
    }

    public function testRemoveFolderClearsInboxFolder(): void
    {
        $mailbox = new Mailbox();
        $folder = (new Folder())->setName('Inbox');

        $link = (new FolderMailbox())
            ->setMailbox($mailbox)
            ->setFolder($folder);
        $mailbox->getFolderLinks()->add($link);
        $mailbox->setInboxFolder($folder);

        $mailbox->removeFolder($folder);

        $this->assertCount(0, $mailbox->getFolderLinks());
        $this->assertNull($mailbox->getInboxFolder());
    }

    public function testAddConnectorIgnoresDuplicate(): void
    {
        $mailbox = new Mailbox();
        $connector = (new MailboxConnector())
            ->setName('Connector')
            ->setEmail('connector@example.com');

        $mailbox->addConnector($connector);
        $mailbox->addConnector($connector);

        $this->assertCount(1, $mailbox->getConnectors());
    }

    public function testRemoveConnectorIgnoresUnknownConnector(): void
    {
        $mailbox = new Mailbox();
        $known = (new MailboxConnector())->setName('Known')->setEmail('known@example.com');
        $unknown = (new MailboxConnector())->setName('Unknown')->setEmail('unknown@example.com');

        $mailbox->addConnector($known);
        $mailbox->removeConnector($unknown);

        $this->assertCount(1, $mailbox->getConnectors());
        $this->assertNull($unknown->getMailbox());
    }

    public function testRemoveConnectorPromotesNextAsPrimary(): void
    {
        $mailbox = new Mailbox();
        $primary = (new MailboxConnector())->setName('Primary')->setEmail('primary@example.com');
        $secondary = (new MailboxConnector())->setName('Secondary')->setEmail('secondary@example.com');

        $mailbox->addConnector($primary);
        $mailbox->addConnector($secondary);
        $this->assertTrue($primary->isPrimary());
        $this->assertFalse($secondary->isPrimary());

        $mailbox->removeConnector($primary);

        $this->assertCount(1, $mailbox->getConnectors());
        $this->assertNull($primary->getMailbox());
        $this->assertTrue($secondary->isPrimary());
    }

    public function testGetPrimaryConnectorFallsBackToFirst(): void
    {
        $mailbox = new Mailbox();
        $first = (new MailboxConnector())->setName('First')->setEmail('first@example.com');
        $second = (new MailboxConnector())->setName('Second')->setEmail('second@example.com');

        $mailbox->addConnector($first);
        $mailbox->addConnector($second);
        $first->setIsPrimary(false);
        $second->setIsPrimary(false);

        $this->assertSame($first, $mailbox->getPrimaryConnector());
    }
}

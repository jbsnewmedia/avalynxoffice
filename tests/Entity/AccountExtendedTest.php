<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Entity;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\FolderAccount;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAlias;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\User;
use PHPUnit\Framework\TestCase;

class AccountExtendedTest extends TestCase
{
    public function testAliasLinksAreManagedAndExposeFullAddresses(): void
    {
        $account = new Account();
        $connector = (new Connector())
            ->setName('Example')
            ->setDomain('example.com');
        $alias = (new AccountAlias())
            ->setAccount($account)
            ->setConnector($connector)
            ->setLocalPart('Info');

        $account->addAlias($alias);
        $account->addAlias($alias);

        $this->assertCount(1, $account->getAliasLinks());
        $this->assertSame('info', $alias->getLocalPart());
        $this->assertSame('info@example.com', $alias->getAddress());
        $this->assertSame($account, $alias->getAccount());
    }

    public function testRemoveAliasDetachesAccount(): void
    {
        $account = new Account();
        $connector = (new Connector())
            ->setName('Example')
            ->setDomain('example.com');
        $alias = (new AccountAlias())
            ->setAccount($account)
            ->setConnector($connector)
            ->setLocalPart('info');
        $account->addAlias($alias);

        $account->removeAlias($alias);
        $account->removeAlias($alias);

        $this->assertCount(0, $account->getAliases());
        $this->assertNull($alias->getAccount());
    }

    public function testAccountAddressDeduplicatesEntries(): void
    {
        $account = new Account();
        $address = (new Address())->setEmail('alias@example.com');

        $account->addAddress($address);
        $account->addAddress($address);

        $this->assertCount(1, $account->getAddressLinks());
        $this->assertCount(1, $account->getAddresses());
    }

    public function testInboxFolderRelationship(): void
    {
        $account = new Account();
        $inboxFolder = new Folder();
        $inboxFolder->setName('Postfach');

        $this->assertNull($account->getInboxFolder());

        $account->setInboxFolder($inboxFolder);
        $this->assertSame($inboxFolder, $account->getInboxFolder());

        $account->setInboxFolder(null);
        $this->assertNull($account->getInboxFolder());
    }

    public function testFolderLinkManagement(): void
    {
        $account = new Account();
        $folder = new Folder();
        $folder->setName('Sent');

        $link = new FolderAccount();
        $link->setAccount($account);
        $link->setFolder($folder);
        $link->setSortOrder(10);

        $account->getFolderLinks()->add($link);

        $this->assertCount(1, $account->getFolderLinks());
        $this->assertSame($link, $account->getFolderLinks()[0]);
    }

    public function testGetFolders(): void
    {
        $account = new Account();
        $folder1 = new Folder();
        $folder1->setName('Inbox');
        $folder2 = new Folder();
        $folder2->setName('Sent');

        $link1 = new FolderAccount();
        $link1->setAccount($account);
        $link1->setFolder($folder1);

        $link2 = new FolderAccount();
        $link2->setAccount($account);
        $link2->setFolder($folder2);

        $account->getFolderLinks()->add($link1);
        $account->getFolderLinks()->add($link2);

        $folders = $account->getFolders();
        $this->assertCount(2, $folders);
        $this->assertTrue($folders->contains($folder1));
        $this->assertTrue($folders->contains($folder2));
    }

    public function testAddUserMethod(): void
    {
        $account = new Account();
        $user = new User();
        $user->setEmail('user@example.com');

        $account->addUser($user);

        $this->assertCount(1, $account->getUserLinks());
        $this->assertTrue($account->getUsers()->contains($user));
    }

    public function testAddUserPreventsDuplicates(): void
    {
        $account = new Account();
        $user = new User();
        $user->setEmail('user@example.com');

        $account->addUser($user);
        $account->addUser($user);

        $this->assertCount(1, $account->getUserLinks());
        $this->assertCount(1, $account->getUsers());
    }

    public function testRemoveUser(): void
    {
        $account = new Account();
        $user = new User();
        $user->setEmail('user@example.com');

        $account->addUser($user);
        $this->assertCount(1, $account->getUserLinks());

        $account->removeUser($user);
        $this->assertCount(0, $account->getUserLinks());
        $this->assertCount(0, $account->getUsers());
    }

    public function testGetUsers(): void
    {
        $account = new Account();
        $user1 = new User();
        $user1->setEmail('user1@example.com');
        $user2 = new User();
        $user2->setEmail('user2@example.com');

        $account->addUser($user1);
        $account->addUser($user2);

        $users = $account->getUsers();
        $this->assertCount(2, $users);
        $this->assertTrue($users->contains($user1));
        $this->assertTrue($users->contains($user2));
    }

    public function testRemoveFolderClearsInboxFolder(): void
    {
        $account = new Account();
        $folder = (new Folder())->setName('Inbox');

        $link = (new FolderAccount())
            ->setAccount($account)
            ->setFolder($folder);
        $account->getFolderLinks()->add($link);
        $account->setInboxFolder($folder);

        $account->removeFolder($folder);

        $this->assertCount(0, $account->getFolderLinks());
        $this->assertNull($account->getInboxFolder());
    }

    public function testRemoveAliasIgnoresUnknownAlias(): void
    {
        $account = new Account();
        $connector = (new Connector())->setName('Example')->setDomain('example.com');
        $known = (new AccountAlias())->setAccount($account)->setConnector($connector)->setLocalPart('known');
        $unknown = (new AccountAlias())->setConnector($connector)->setLocalPart('unknown');

        $account->addAlias($known);
        $account->removeAlias($unknown);

        $this->assertCount(1, $account->getAliases());
        $this->assertSame($account, $known->getAccount());
    }

    public function testConnectorAliasHelpersFindAndRemoveByLocalPart(): void
    {
        $connector = (new Connector())->setName('Example')->setDomain('example.com');
        $known = (new AccountAlias())->setConnector($connector)->setLocalPart('info');
        $unknown = (new AccountAlias())->setConnector($connector)->setLocalPart('other');
        $connector->addAlias($known);
        $connector->addAlias($known);

        $this->assertCount(1, $connector->getAliases());
        $this->assertSame($known, $connector->getAliasByLocalPart('INFO'));
        $this->assertNull($connector->getAliasByLocalPart('missing'));

        $connector->removeAlias($known);
        $connector->removeAlias($unknown);

        $this->assertCount(0, $connector->getAliases());
        $this->assertNull($known->getConnector());
    }

    public function testConnectorDomainIsNormalized(): void
    {
        $connector = (new Connector())->setName('Example')->setDomain(' Example.COM ');

        $this->assertSame('example.com', $connector->getDomain());
    }

    public function testRemoveAddressAndPrimaryAddressFallbacks(): void
    {
        $account = new Account();
        $address = (new Address())->setEmail('contact@example.com');
        $account->addAddress($address);
        self::assertSame($address, $account->getPrimaryAddress());

        $account->removeAddress($address);
        $account->removeAddress($address);

        self::assertCount(0, $account->getAddresses());
        self::assertNull($account->getPrimaryAddress());
    }
}

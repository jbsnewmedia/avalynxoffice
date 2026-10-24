<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Entity;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAlias;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\User;
use PHPUnit\Framework\TestCase;

class EntityTest extends TestCase
{
    public function testEmailGettersSetters(): void
    {
        $email = new Email();
        $email->setSubject('Test Subject');
        $this->assertEquals('Test Subject', $email->getSubject());

        $email->setIsFavorite(true);
        $this->assertTrue($email->isFavorite());

        $now = new \DateTimeImmutable();
        $email->setReceivedAt($now);
        $this->assertEquals($now, $email->getReceivedAt());
    }

    public function testAccountGettersSetters(): void
    {
        $account = new Account();
        $account->setName('Test Account');
        $account->setEmail('account@example.com');

        $this->assertEquals('Test Account', $account->getName());
        $this->assertEquals('account@example.com', $account->getEmail());
    }

    public function testAccountAliasHandling(): void
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

        $this->assertCount(1, $account->getAliases());
        $this->assertSame($alias, $account->getAliasLinks()->first());
    }

    public function testAccountAddressHandling(): void
    {
        $account = new Account();
        $address = (new Address())->setEmail('alias@example.com');

        $account->addAddress($address);

        $this->assertCount(1, $account->getAddressLinks());
        $this->assertCount(1, $account->getAddresses());
        $this->assertSame($address, $account->getAddresses()->first());
    }

    public function testAccountUserHandling(): void
    {
        $account = new Account();
        $user = new User();

        $account->addUser($user);
        $this->assertCount(1, $account->getUserLinks());
        $this->assertCount(1, $account->getUsers());
        $this->assertSame($user, $account->getUsers()[0]);

        $account->removeUser($user);
        $this->assertCount(0, $account->getUserLinks());
    }
}

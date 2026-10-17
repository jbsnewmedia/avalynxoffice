<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Entity;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\MailboxConnector;
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

    public function testMailboxGettersSetters(): void
    {
        $mailbox = new Mailbox();
        $mailbox->setName('Test Mailbox');
        $mailbox->setEmail('mailbox@example.com');

        $this->assertEquals('Test Mailbox', $mailbox->getName());
        $this->assertEquals('mailbox@example.com', $mailbox->getEmail());
    }

    public function testMailboxConnectorHandling(): void
    {
        $mailbox = new Mailbox();
        $connector = (new MailboxConnector())
            ->setName('Connector')
            ->setEmail('connector@example.com')
            ->setIsPrimary(true);

        $mailbox->addConnector($connector);

        $this->assertCount(1, $mailbox->getConnectors());
        $this->assertSame($connector, $mailbox->getPrimaryConnector());
    }

    public function testMailboxAddressHandling(): void
    {
        $mailbox = new Mailbox();
        $address = (new Address())->setEmail('alias@example.com');

        $mailbox->addAddress($address);

        $this->assertCount(1, $mailbox->getAddressLinks());
        $this->assertCount(1, $mailbox->getAddresses());
        $this->assertSame($address, $mailbox->getAddresses()->first());
    }

    public function testMailboxUserHandling(): void
    {
        $mailbox = new Mailbox();
        $user = new User();

        $mailbox->addUser($user);
        $this->assertCount(1, $mailbox->getUserLinks());
        $this->assertCount(1, $mailbox->getUsers());
        $this->assertSame($user, $mailbox->getUsers()[0]);

        $mailbox->removeUser($user);
        $this->assertCount(0, $mailbox->getUserLinks());
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Entity;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\Body;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailAddressLink;
use AvalynxOffice\Entity\EmailAttachmentLink;
use AvalynxOffice\Entity\EmailBodyLink;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\BodyContentType;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\FolderAccount;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAddress;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Entity\UserAccount;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class EntityGapFillTest extends TestCase
{
    public function testAddressAndBodyExposeIdentifiersAndCollections(): void
    {
        $address = (new Address())
            ->setEmail('alias@example.com')
            ->setName('Alias');
        $body = (new Body())
            ->setContent('Hello');

        self::assertNotNull($address->getId());
        self::assertSame('alias@example.com', $address->getEmail());
        self::assertSame('Alias', $address->getName());
        self::assertCount(0, $address->getEmailAddresses());

        self::assertNotNull($body->getId());
        self::assertSame('Hello', $body->getContent());
        self::assertCount(0, $body->getEmailBodies());
    }

    public function testAttachmentExposesAllPropertiesAndCollection(): void
    {
        $attachment = (new Attachment())
            ->setName('test.pdf')
            ->setSize(123)
            ->setMimeType('application/pdf')
            ->setSha1('abc123')
            ->setFilePath('/tmp/test.pdf');

        self::assertNotNull($attachment->getId());
        self::assertSame('test.pdf', $attachment->getName());
        self::assertSame(123, $attachment->getSize());
        self::assertSame('application/pdf', $attachment->getMimeType());
        self::assertSame('abc123', $attachment->getSha1());
        self::assertSame('/tmp/test.pdf', $attachment->getFilePath());
        self::assertCount(0, $attachment->getEmailAttachments());
    }

    public function testLinkEntitiesExposeIdentifiersAndRelations(): void
    {
        $email = new Email();
        $address = (new Address())->setEmail('sender@example.com');
        $attachment = (new Attachment())->setName('doc.txt')->setSize(5)->setMimeType('text/plain')->setSha1('sha1')->setFilePath('/tmp/doc.txt');
        $body = (new Body())->setContent('Body');
        $folder = (new Folder())->setName('Inbox');

        $addressLink = (new EmailAddressLink())
            ->setEmail($email)
            ->setAddress($address)
            ->setType(EmailAddressType::SENDER);
        $attachmentLink = (new EmailAttachmentLink())
            ->setEmail($email)
            ->setAttachment($attachment);
        $bodyLink = (new EmailBodyLink())
            ->setEmail($email)
            ->setBody($body)
            ->setContentType(BodyContentType::TEXT);
        $folderLink = (new EmailFolder())
            ->setEmail($email)
            ->setFolder($folder);

        self::assertNotNull($addressLink->getId());
        self::assertSame($email, $addressLink->getEmail());
        self::assertSame($address, $addressLink->getAddress());
        self::assertSame(EmailAddressType::SENDER, $addressLink->getType());

        self::assertNotNull($attachmentLink->getId());
        self::assertSame($email, $attachmentLink->getEmail());
        self::assertSame($attachment, $attachmentLink->getAttachment());

        self::assertNotNull($bodyLink->getId());
        self::assertSame($email, $bodyLink->getEmail());
        self::assertSame($body, $bodyLink->getBody());
        self::assertSame(BodyContentType::TEXT, $bodyLink->getContentType());

        self::assertSame($email, $folderLink->getEmail());
        self::assertSame($folder, $folderLink->getFolder());
    }

    public function testEmailIdentifierAndTimestampableTraitBranches(): void
    {
        $email = new Email();
        $customId = Uuid::v7();
        $createdAt = new \DateTimeImmutable('-1 day');

        $email->setId($customId);
        $email->setCreatedAt($createdAt);
        $email->setCreatedAtValue();
        $beforeUpdate = $email->getUpdatedAt();
        $email->setUpdatedAtValue();

        self::assertSame($customId, $email->getId());
        self::assertSame($createdAt, $email->getCreatedAt());
        self::assertSame($createdAt, $beforeUpdate);
        self::assertInstanceOf(\DateTimeImmutable::class, $email->getUpdatedAt());
        self::assertNotSame($beforeUpdate, $email->getUpdatedAt());
    }

    public function testAccountLinkEntitiesAndConnectorProperties(): void
    {
        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $folder = (new Folder())->setName('Inbox');
        $address = (new Address())->setEmail('support+alias@example.com');
        $user = (new User())->setEmail('user@example.com')->setPassword('secret');

        $folderAccount = (new FolderAccount())
            ->setAccount($account)
            ->setFolder($folder)
            ->setSortOrder(5);
        $accountAddress = (new AccountAddress())
            ->setAccount($account)
            ->setAddress($address);
        $userAccount = (new UserAccount())
            ->setUser($user)
            ->setAccount($account);
        $connector = (new Connector())
            ->setName('Primary')
            ->setDomain('example.com')
            ->setPop3Host('pop.example.com')
            ->setPop3Port(995)
            ->setPop3Username('pop-user')
            ->setPop3Password('pop-pass')
            ->setPop3Security('ssl')
            ->setPop3DeleteAfterFetch(false)
            ->setSmtpHost('smtp.example.com')
            ->setSmtpPort(465)
            ->setSmtpUsername('smtp-user')
            ->setSmtpPassword('smtp-pass')
            ->setSmtpSecurity('tls')
            ->setDefaultAccount($account);

        self::assertSame($account, $folderAccount->getAccount());
        self::assertSame($folder, $folderAccount->getFolder());
        self::assertSame(5, $folderAccount->getSortOrder());

        self::assertSame($account, $accountAddress->getAccount());
        self::assertSame($address, $accountAddress->getAddress());

        self::assertSame($user, $userAccount->getUser());
        self::assertSame($account, $userAccount->getAccount());

        self::assertNotNull($connector->getId());
        self::assertSame($account, $connector->getDefaultAccount());
        self::assertSame('Primary', $connector->getName());
        self::assertSame('example.com', $connector->getDomain());
        self::assertSame('pop.example.com', $connector->getPop3Host());
        self::assertSame(995, $connector->getPop3Port());
        self::assertSame('pop-user', $connector->getPop3Username());
        self::assertSame('pop-pass', $connector->getPop3Password());
        self::assertSame('ssl', $connector->getPop3Security());
        self::assertFalse($connector->getPop3DeleteAfterFetch());
        self::assertSame('smtp.example.com', $connector->getSmtpHost());
        self::assertSame(465, $connector->getSmtpPort());
        self::assertSame('smtp-user', $connector->getSmtpUsername());
        self::assertSame('smtp-pass', $connector->getSmtpPassword());
        self::assertSame('tls', $connector->getSmtpSecurity());
    }

    public function testUserIdentifierRolesAndAccountRemovalBranches(): void
    {
        $user = (new User())
            ->setEmail('USER@Example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_ADMIN', 'ROLE_ADMIN']);
        $account = (new Account())
            ->setName('Inbox')
            ->setEmail('inbox@example.com');

        $user->addAccount($account);
        $user->removeAccount(new Account());
        $user->removeAccount($account);

        self::assertSame('user@example.com', $user->getEmail());
        self::assertSame('user@example.com', $user->getUserIdentifier());
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
        self::assertCount(0, $user->getAccounts());

        $emptyUser = (new User())
            ->setEmail('')
            ->setPassword('secret');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Email must not be empty');
        $emptyUser->getUserIdentifier();
    }

    public function testUserAccountLinksAndDuplicateAdd(): void
    {
        $user = (new User())->setEmail('owner@example.com')->setPassword('secret');
        $account = (new Account())->setName('Inbox')->setEmail('inbox@example.com');

        $user->addAccount($account);
        $user->addAccount($account);

        self::assertCount(1, $user->getAccountLinks());
        self::assertCount(1, $user->getAccounts());
    }

    public function testEmailFolderIdentifierAndUpdatedAtSetter(): void
    {
        $emailFolder = new EmailFolder();
        self::assertInstanceOf(UuidV7::class, $emailFolder->getId());

        $email = new Email();
        $updatedAt = new \DateTimeImmutable('-2 hours');
        $email->setUpdatedAt($updatedAt);

        self::assertSame($updatedAt, $email->getUpdatedAt());
    }
}

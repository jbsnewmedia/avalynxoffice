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
use PHPUnit\Framework\TestCase;

class EmailExtendedTest extends TestCase
{
    public function testEmailDraftFunctionality(): void
    {
        $email = new Email();

        $this->assertFalse($email->isDraft());
        $email->setIsDraft(true);
        $this->assertTrue($email->isDraft());
    }

    public function testEmailSendingStates(): void
    {
        $email = new Email();

        // Initial state
        $this->assertFalse($email->isSending());
        $this->assertFalse($email->isSent());

        // Sending state
        $email->setIsSending(true);
        $this->assertTrue($email->isSending());

        // Sent state
        $email->setIsSending(false);
        $email->setIsSent(true);
        $this->assertTrue($email->isSent());
        $this->assertFalse($email->isSending());
    }

    public function testEmailImportantFlag(): void
    {
        $email = new Email();

        $this->assertFalse($email->isImportant());
        $email->setIsImportant(true);
        $this->assertTrue($email->isImportant());
    }

    public function testEmailScheduledAt(): void
    {
        $email = new Email();
        $scheduledTime = new \DateTimeImmutable('+1 hour');

        $this->assertNull($email->getScheduledAt());
        $email->setScheduledAt($scheduledTime);
        $this->assertEquals($scheduledTime, $email->getScheduledAt());
    }

    public function testEmailContentHash(): void
    {
        $email = new Email();
        $hash = hash('sha256', 'test content');

        $this->assertNull($email->getContentHash());
        $email->setContentHash($hash);
        $this->assertEquals($hash, $email->getContentHash());
    }

    public function testEmailImportKey(): void
    {
        $email = new Email();
        $importKey = 'account-123:uid-456';

        $this->assertNull($email->getImportKey());
        $email->setImportKey($importKey);
        $this->assertEquals($importKey, $email->getImportKey());
    }

    public function testEmailMessageId(): void
    {
        $email = new Email();
        $messageId = '<unique-id@example.com>';

        $this->assertNull($email->getMessageId());
        $email->setMessageId($messageId);
        $this->assertEquals($messageId, $email->getMessageId());
    }

    public function testEmailAddressLinkManagement(): void
    {
        $email = new Email();
        $address = new Address();
        $address->setEmail('test@example.com');

        $link = new EmailAddressLink();
        $link->setAddress($address);
        $link->setType(EmailAddressType::SENDER);

        $email->addEmailAddress($link);

        $this->assertCount(1, $email->getEmailAddresses());
        $this->assertSame($link, $email->getEmailAddresses()[0]);
        $this->assertSame($email, $link->getEmail());

        // Test remove
        $email->removeEmailAddress($link);
        $this->assertCount(0, $email->getEmailAddresses());
    }

    public function testEmailBodyLinkManagement(): void
    {
        $email = new Email();
        $body = new Body();
        $body->setContent('Test content');

        $link = new EmailBodyLink();
        $link->setBody($body);
        $link->setContentType(BodyContentType::TEXT);

        $email->addEmailBody($link);

        $this->assertCount(1, $email->getEmailBodies());
        $this->assertSame($link, $email->getEmailBodies()[0]);
        $this->assertSame($email, $link->getEmail());

        // Test remove
        $email->removeEmailBody($link);
        $this->assertCount(0, $email->getEmailBodies());
    }

    public function testEmailAttachmentLinkManagement(): void
    {
        $email = new Email();
        $attachment = new Attachment();
        $attachment->setName('test.pdf');
        $attachment->setMimeType('application/pdf');
        $attachment->setSize(1024);

        $link = new EmailAttachmentLink();
        $link->setAttachment($attachment);

        $email->addEmailAttachment($link);

        $this->assertCount(1, $email->getEmailAttachments());
        $this->assertSame($link, $email->getEmailAttachments()[0]);
        $this->assertSame($email, $link->getEmail());

        // Test remove
        $email->removeEmailAttachment($link);
        $this->assertCount(0, $email->getEmailAttachments());
    }

    public function testEmailFolderManagement(): void
    {
        $email = new Email();
        $folder = new Folder();
        $folder->setName('Inbox');

        $emailFolder = new EmailFolder();
        $emailFolder->setFolder($folder);

        $email->addEmailFolder($emailFolder);

        $this->assertCount(1, $email->getEmailFolders());
        $this->assertSame($emailFolder, $email->getEmailFolders()[0]);
        $this->assertSame($email, $emailFolder->getEmail());

        // Test remove
        $email->removeEmailFolder($emailFolder);
        $this->assertCount(0, $email->getEmailFolders());
    }

    public function testEmailCompleteWorkflow(): void
    {
        $email = new Email();

        // Draft email
        $email->setIsDraft(true);
        $email->setSubject('Test Draft');
        $email->setScheduledAt(new \DateTimeImmutable('+1 hour'));

        $this->assertTrue($email->isDraft());
        $this->assertFalse($email->isSending());
        $this->assertFalse($email->isSent());

        // Move to sending
        $email->setIsDraft(false);
        $email->setIsSending(true);

        $this->assertFalse($email->isDraft());
        $this->assertTrue($email->isSending());
        $this->assertFalse($email->isSent());

        // Mark as sent
        $email->setIsSending(false);
        $email->setIsSent(true);
        $email->setSentAt(new \DateTimeImmutable());

        $this->assertFalse($email->isDraft());
        $this->assertFalse($email->isSending());
        $this->assertTrue($email->isSent());
        $this->assertInstanceOf(\DateTimeImmutable::class, $email->getSentAt());
    }

    public function testConnectorRoutingFieldsRoundTrip(): void
    {
        $email = new Email();
        $connector = (new \AvalynxOffice\Entity\Connector())
            ->setName('Example')
            ->setDomain('example.com');

        $email->setConnector($connector);
        $email->setMatchedLocalPart(' Info ');

        self::assertSame($connector, $email->getConnector());
        self::assertSame('info', $email->getMatchedLocalPart());

        $email->setMatchedLocalPart(null);
        self::assertNull($email->getMatchedLocalPart());
        self::assertNull($email->getMatchedLocalPart());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Plugin\MailActionBundle\Service;

use App\Entity\Address;
use App\Entity\Body;
use App\Entity\Email;
use App\Entity\EmailAddressLink;
use App\Entity\EmailAttachmentLink;
use App\Entity\EmailBodyLink;
use App\Entity\EmailFolder;
use App\Entity\Enum\BodyContentType;
use App\Entity\Enum\EmailAddressType;
use App\Entity\Folder;
use App\Entity\FolderMailbox;
use App\Entity\Mailbox;
use App\Repository\MailboxRepository;
use App\Service\Mail\AttachmentStorage;
use App\Service\Mail\FolderManager;
use App\Tests\Support\AllowAllLicenseEnforcement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use JBSNewMedia\MailActionBundle\Service\MailDraftService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MailDraftServiceTest extends TestCase
{
    public function testCreateComposeDraftUsesFirstMailboxAsSender(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $mailboxRepository = $this->createMock(MailboxRepository::class);
        $folderManager = $this->createStub(FolderManager::class);
        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $mailbox = (new Mailbox())
            ->setName('Primary')
            ->setEmail('primary@example.com');
        $mailbox->addAddress(
            (new Address())
                ->setEmail('alias@example.com')
                ->setName('Alias')
        );
        $address = (new Address())
            ->setEmail('alias@example.com')
            ->setName('Alias');

        $addressRepository = $this->createMock(EntityRepository::class);
        $addressRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'alias@example.com'])
            ->willReturn($address);

        $mailboxRepository
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([$mailbox]);

        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(Address::class)
            ->willReturn($addressRepository);

        $entityManager
            ->expects(self::never())
            ->method('persist');

        $service = new MailDraftService($entityManager, $mailboxRepository, $folderManager, $attachmentStorage, $translator, new AllowAllLicenseEnforcement());

        $draft = $service->createComposeDraft();

        self::assertTrue($draft->isOutgoing());
        self::assertTrue($draft->isDraft());
        self::assertSame($mailbox, $draft->getMailbox());
        self::assertCount(1, $draft->getEmailAddresses());
        self::assertSame(EmailAddressType::SENDER, $draft->getEmailAddresses()->first()->getType());
        self::assertSame('alias@example.com', $draft->getEmailAddresses()->first()->getAddress()?->getEmail());
    }

    public function testCreateComposeDraftWithoutMailboxReturnsEmptyDraft(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $mailboxRepository = $this->createMock(MailboxRepository::class);
        $folderManager = $this->createStub(FolderManager::class);
        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $mailboxRepository
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([]);

        $entityManager
            ->expects(self::never())
            ->method('getRepository');

        $service = new MailDraftService($entityManager, $mailboxRepository, $folderManager, $attachmentStorage, $translator, new AllowAllLicenseEnforcement());
        $draft = $service->createComposeDraft();

        self::assertTrue($draft->isOutgoing());
        self::assertTrue($draft->isDraft());
        self::assertNull($draft->getMailbox());
        self::assertCount(0, $draft->getEmailAddresses());
    }

    public function testCreateReplyDraftCopiesParticipantsAndQuotedBody(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $mailboxRepository = $this->createStub(MailboxRepository::class);
        $folderManager = $this->createMock(FolderManager::class);
        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $translator = $this->createTranslatorStub();
        $service = new MailDraftService($entityManager, $mailboxRepository, $folderManager, $attachmentStorage, $translator, new AllowAllLicenseEnforcement());

        $mailbox = (new Mailbox())
            ->setName('Primary')
            ->setEmail('primary@example.com');

        $originalEmail = (new Email())
            ->setMailbox($mailbox)
            ->setSubject('Original subject')
            ->setPreviewBody('Preview excerpt')
            ->setReceivedAt(new \DateTimeImmutable('2026-04-27 10:15:00'));

        $recipientAddress = (new Address())->setEmail('primary@example.com');
        $senderAddress = (new Address())->setEmail('sender@example.com');

        $recipientLink = (new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress($recipientAddress)
            ->setType(EmailAddressType::RECIPIENT);
        $senderLink = (new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress($senderAddress)
            ->setType(EmailAddressType::SENDER);
        $originalEmail->addEmailAddress($recipientLink);
        $originalEmail->addEmailAddress($senderLink);

        $body = (new Body())->setContent('Original text body');
        $bodyLink = (new EmailBodyLink())
            ->setEmail($originalEmail)
            ->setBody($body)
            ->setContentType(BodyContentType::TEXT);
        $originalEmail->addEmailBody($bodyLink);

        $folderManager
            ->expects(self::once())
            ->method('syncEmailSystemFolders')
            ->with(self::isInstanceOf(Email::class));

        $entityManager
            ->expects(self::atLeastOnce())
            ->method('persist');

        $entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $draft = $service->createReplyDraft($originalEmail);

        $draftAddresses = array_values($draft->getEmailAddresses()->toArray());
        $draftBodies = array_values($draft->getEmailBodies()->toArray());

        self::assertSame('Re Original subject', $draft->getSubject());
        self::assertSame($mailbox, $draft->getMailbox());
        self::assertCount(2, $draftAddresses);
        self::assertSame(EmailAddressType::SENDER, $draftAddresses[0]->getType());
        self::assertSame('primary@example.com', $draftAddresses[0]->getAddress()?->getEmail());
        self::assertSame(EmailAddressType::RECIPIENT, $draftAddresses[1]->getType());
        self::assertSame('sender@example.com', $draftAddresses[1]->getAddress()?->getEmail());
        self::assertStringContainsString('On 27.04.2026 10:15 sender wrote Preview excerpt', $draftBodies[0]->getBody()?->getContent() ?? '');
        self::assertStringContainsString('Original text body', $draftBodies[0]->getBody()?->getContent() ?? '');
    }

    public function testCreateReplyAllDraftAddsReplyToRecipientsAndCc(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $mailboxRepository = $this->createStub(MailboxRepository::class);
        $folderManager = $this->createMock(FolderManager::class);
        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $translator = $this->createTranslatorStub();
        $service = new MailDraftService($entityManager, $mailboxRepository, $folderManager, $attachmentStorage, $translator, new AllowAllLicenseEnforcement());

        $mailbox = (new Mailbox())
            ->setName('Primary')
            ->setEmail('primary@example.com');

        $originalEmail = (new Email())
            ->setMailbox($mailbox)
            ->setSubject('Original subject')
            ->setPreviewBody('Preview excerpt')
            ->setReceivedAt(new \DateTimeImmutable('2026-04-27 10:15:00'));

        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress((new Address())->setEmail('primary@example.com'))
            ->setType(EmailAddressType::RECIPIENT));
        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress((new Address())->setEmail('sender@example.com'))
            ->setType(EmailAddressType::SENDER));
        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress((new Address())->setEmail('reply@example.com'))
            ->setType(EmailAddressType::REPLY_TO));
        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress((new Address())->setEmail('teammate@example.com'))
            ->setType(EmailAddressType::RECIPIENT));
        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress((new Address())->setEmail('copy@example.com'))
            ->setType(EmailAddressType::CC));

        $originalEmail->addEmailBody((new EmailBodyLink())
            ->setEmail($originalEmail)
            ->setBody((new Body())->setContent('Original text body'))
            ->setContentType(BodyContentType::TEXT));

        $folderManager
            ->expects(self::once())
            ->method('syncEmailSystemFolders')
            ->with(self::isInstanceOf(Email::class));

        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::exactly(2))->method('flush');

        $draft = $service->createReplyAllDraft($originalEmail);

        $draftAddresses = array_values($draft->getEmailAddresses()->toArray());

        self::assertCount(4, $draftAddresses);
        self::assertSame('primary@example.com', $draftAddresses[0]->getAddress()?->getEmail());
        self::assertSame('reply@example.com', $draftAddresses[1]->getAddress()?->getEmail());
        self::assertSame('teammate@example.com', $draftAddresses[2]->getAddress()?->getEmail());
        self::assertSame(EmailAddressType::CC, $draftAddresses[3]->getType());
        self::assertSame('copy@example.com', $draftAddresses[3]->getAddress()?->getEmail());
    }

    public function testCreateForwardDraftCopiesAttachments(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $mailboxRepository = $this->createStub(MailboxRepository::class);
        $folderManager = $this->createMock(FolderManager::class);
        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $translator = $this->createTranslatorStub();
        $service = new MailDraftService($entityManager, $mailboxRepository, $folderManager, $attachmentStorage, $translator, new AllowAllLicenseEnforcement());

        $mailbox = (new Mailbox())
            ->setName('Primary')
            ->setEmail('primary@example.com');

        $attachment = (new \App\Entity\Attachment())
            ->setName('offer.pdf')
            ->setMimeType('application/pdf')
            ->setSize(123)
            ->setSha1('abc')
            ->setFilePath('offer.pdf');

        $originalEmail = (new Email())
            ->setMailbox($mailbox)
            ->setSubject('Original subject')
            ->setPreviewBody('Preview excerpt')
            ->setReceivedAt(new \DateTimeImmutable('2026-04-27 10:15:00'));
        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress((new Address())->setEmail('primary@example.com'))
            ->setType(EmailAddressType::RECIPIENT));
        $originalEmail->addEmailBody((new EmailBodyLink())
            ->setEmail($originalEmail)
            ->setBody((new Body())->setContent('Original text body'))
            ->setContentType(BodyContentType::TEXT));
        $originalEmail->addEmailAttachment((new EmailAttachmentLink())
            ->setEmail($originalEmail)
            ->setAttachment($attachment));

        $folderManager
            ->expects(self::once())
            ->method('syncEmailSystemFolders')
            ->with(self::isInstanceOf(Email::class));

        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::exactly(2))->method('flush');

        $draft = $service->createForwardDraft($originalEmail);

        self::assertCount(1, $draft->getEmailAttachments());
        self::assertSame($attachment, $draft->getEmailAttachments()->first()?->getAttachment());
    }

    public function testCreateReplyDraftUsesUnknownDateAndPreviewFallbackWithoutTextBody(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $folderManager = $this->createMock(FolderManager::class);
        $service = new MailDraftService(
            $entityManager,
            $this->createStub(MailboxRepository::class),
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $this->createTranslatorStub(),
            new AllowAllLicenseEnforcement(),
        );

        $mailbox = (new Mailbox())->setName('Primary')->setEmail('primary@example.com');
        $originalEmail = (new Email())
            ->setMailbox($mailbox)
            ->setSubject('Original subject')
            ->setPreviewBody('Preview fallback');
        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress((new Address())->setEmail('primary@example.com'))
            ->setType(EmailAddressType::RECIPIENT));
        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress((new Address())->setEmail('sender@example.com'))
            ->setType(EmailAddressType::SENDER));
        $originalEmail->addEmailBody((new EmailBodyLink())
            ->setEmail($originalEmail)
            ->setBody((new Body())->setContent('<p>HTML only</p>'))
            ->setContentType(BodyContentType::HTML));

        $folderManager->expects(self::once())->method('syncEmailSystemFolders');
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::exactly(2))->method('flush');

        $draft = $service->createReplyDraft($originalEmail);

        self::assertStringContainsString('On unknown sender wrote Preview fallback', $draft->getEmailBodies()->first()?->getBody()?->getContent() ?? '');
        self::assertStringContainsString('Preview fallback', $draft->getEmailBodies()->first()?->getBody()?->getContent() ?? '');
    }

    public function testSaveEmailFromFormReplacesRecipientsAndBodyAndAssignsFolder(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $mailboxRepository = $this->createStub(MailboxRepository::class);
        $folderManager = $this->createMock(FolderManager::class);
        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $service = new MailDraftService($entityManager, $mailboxRepository, $folderManager, $attachmentStorage, $translator, new AllowAllLicenseEnforcement());

        $mailbox = (new Mailbox())
            ->setName('Primary')
            ->setEmail('primary@example.com');

        $email = (new Email())
            ->setMailbox($mailbox)
            ->setIsDraft(true);

        $existingRecipient = (new EmailAddressLink())
            ->setEmail($email)
            ->setAddress((new Address())->setEmail('old@example.com'))
            ->setType(EmailAddressType::RECIPIENT);
        $existingSender = (new EmailAddressLink())
            ->setEmail($email)
            ->setAddress((new Address())->setEmail('primary@example.com'))
            ->setType(EmailAddressType::SENDER);
        $email->addEmailAddress($existingRecipient);
        $email->addEmailAddress($existingSender);

        $existingBodyLink = (new EmailBodyLink())
            ->setEmail($email)
            ->setBody((new Body())->setContent('Old body'))
            ->setContentType(BodyContentType::TEXT);
        $email->addEmailBody($existingBodyLink);

        $addressRepository = $this->createMock(EntityRepository::class);
        $addressRepository
            ->expects(self::exactly(3))
            ->method('findOneBy')
            ->willReturnCallback(static fn (array $criteria): ?Address => match ($criteria['email']) {
                'primary@example.com' => (new Address())->setEmail('primary@example.com'),
                'new@example.com' => null,
                'second@example.com' => (new Address())->setEmail('second@example.com'),
                default => null,
            });

        $entityManager
            ->expects(self::exactly(3))
            ->method('getRepository')
            ->with(Address::class)
            ->willReturn($addressRepository);

        $entityManager
            ->expects(self::exactly(3))
            ->method('remove')
            ->with(self::logicalOr(
                self::identicalTo($existingRecipient),
                self::identicalTo($existingSender),
                self::identicalTo($existingBodyLink),
            ));

        $entityManager
            ->expects(self::atLeastOnce())
            ->method('persist');

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $folderManager
            ->expects(self::once())
            ->method('syncEmailSystemFolders')
            ->with($email);

        $service->saveEmailFromForm($email, 'primary@example.com', 'new@example.com, second@example.com', '', '', '', 'Body payload', false, null);

        $addresses = array_values($email->getEmailAddresses()->toArray());
        $bodies = array_values($email->getEmailBodies()->toArray());

        self::assertFalse($email->isDraft());
        self::assertSame('Body payload', $email->getPreviewBody());
        self::assertCount(3, $addresses);
        self::assertSame('primary@example.com', $addresses[0]->getAddress()?->getEmail());
        self::assertSame('new@example.com', $addresses[1]->getAddress()?->getEmail());
        self::assertSame('second@example.com', $addresses[2]->getAddress()?->getEmail());
        self::assertCount(1, $bodies);
        self::assertSame('Body payload', $bodies[0]->getBody()?->getContent());
    }

    public function testSaveEmailFromFormSkipsFolderSyncWithoutMailbox(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $mailboxRepository = $this->createStub(MailboxRepository::class);
        $folderManager = $this->createMock(FolderManager::class);
        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $service = new MailDraftService($entityManager, $mailboxRepository, $folderManager, $attachmentStorage, $translator, new AllowAllLicenseEnforcement());

        $email = new Email();

        $entityManager
            ->expects(self::atLeastOnce())
            ->method('persist');

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $folderManager
            ->expects(self::never())
            ->method('syncEmailSystemFolders');

        $service->saveEmailFromForm($email, null, '', '', '', '', 'Standalone draft', true, null);

        self::assertTrue($email->isDraft());
        self::assertSame('Standalone draft', $email->getPreviewBody());
    }

    public function testPrivateHelpersResolveMailboxAndSenderFallbacks(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $mailboxRepository = $this->createStub(MailboxRepository::class);
        $folderManager = $this->createStub(FolderManager::class);
        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $translator = $this->createTranslatorStub();
        $service = new MailDraftService($entityManager, $mailboxRepository, $folderManager, $attachmentStorage, $translator, new AllowAllLicenseEnforcement());

        $mailbox = (new Mailbox())
            ->setName('Primary')
            ->setEmail('primary@example.com');
        $folder = (new Folder())->setName('Inbox');
        $mailboxLink = (new FolderMailbox())
            ->setMailbox($mailbox)
            ->setFolder($folder);
        $mailbox->addFolder($folder);

        $mailboxLinksProperty = new \ReflectionProperty(Folder::class, 'mailboxLinks');
        $mailboxLinksProperty->setValue($folder, new ArrayCollection([$mailboxLink]));

        $originalEmail = (new Email())
            ->setPreviewBody('Preview only');
        $folderLink = (new EmailFolder())
            ->setEmail($originalEmail)
            ->setFolder($folder);
        $originalEmail->addEmailFolder($folderLink);

        $resolveMailbox = new \ReflectionMethod(MailDraftService::class, 'resolveMailbox');
        self::assertSame($mailbox, $resolveMailbox->invoke($service, $originalEmail));

        $resolveOriginalContent = new \ReflectionMethod(MailDraftService::class, 'resolveOriginalContent');
        self::assertSame('Preview only', $resolveOriginalContent->invoke($service, $originalEmail));

        $addressRepository = $this->createMock(EntityRepository::class);
        $addressRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'primary@example.com'])
            ->willReturn(null);

        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(Address::class)
            ->willReturn($addressRepository);

        $entityManager
            ->expects(self::atLeastOnce())
            ->method('persist');

        $draft = (new Email())->setMailbox($mailbox);
        $copyMailboxAddressAsSender = new \ReflectionMethod(MailDraftService::class, 'copyMailboxAddressAsSender');
        $copyMailboxAddressAsSender->invoke($service, $originalEmail, $draft);

        self::assertCount(1, $draft->getEmailAddresses());
        self::assertSame('primary@example.com', $draft->getEmailAddresses()->first()->getAddress()?->getEmail());
        self::assertSame(EmailAddressType::SENDER, $draft->getEmailAddresses()->first()->getType());
    }

    public function testPrivateHelpersReturnNullMailboxAndMatchMailboxAliasSenderWithoutDuplicates(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new MailDraftService(
            $entityManager,
            $this->createStub(MailboxRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $this->createTranslatorStub(),
            new AllowAllLicenseEnforcement(),
        );

        $resolveMailbox = new \ReflectionMethod(MailDraftService::class, 'resolveMailbox');

        $emailWithMissingFolder = new Email();
        $emailWithMissingFolder->addEmailFolder((new class extends EmailFolder {
            #[\Override]
            public function getFolder(): ?Folder
            {
                return null;
            }
        })->setEmail($emailWithMissingFolder));

        self::assertNull($resolveMailbox->invoke($service, $emailWithMissingFolder));

        $alias = (new Address())->setEmail('alias@example.com')->setName('Alias');
        $mailbox = (new Mailbox())
            ->setName('Primary')
            ->setEmail('primary@example.com');
        $mailbox->addAddress($alias);

        $originalEmail = new Email();
        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress((new Address())->setEmail('alias@example.com'))
            ->setType(EmailAddressType::RECIPIENT));
        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setType(EmailAddressType::RECIPIENT));

        $draft = (new Email())->setMailbox($mailbox);
        $draft->addEmailAddress((new EmailAddressLink())
            ->setEmail($draft)
            ->setAddress($alias)
            ->setType(EmailAddressType::SENDER));

        $entityManager->expects(self::never())->method('persist');

        $copyMailboxAddressAsSender = new \ReflectionMethod(MailDraftService::class, 'copyMailboxAddressAsSender');
        $copyMailboxAddressAsSender->invoke($service, $originalEmail, $draft);

        self::assertCount(1, array_filter(
            $draft->getEmailAddresses()->toArray(),
            static fn (EmailAddressLink $link): bool => EmailAddressType::SENDER === $link->getType(),
        ));
        self::assertSame($alias, $draft->getEmailAddresses()->first()?->getAddress());
    }

    public function testPrivateHelpersSkipIrrelevantReplyLinksBeforeFallingBackToRecipientSender(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new MailDraftService(
            $entityManager,
            $this->createStub(MailboxRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $this->createTranslatorStub(),
            new AllowAllLicenseEnforcement(),
        );

        $originalEmail = new Email();
        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress((new Address())->setEmail('sender@example.com'))
            ->setType(EmailAddressType::SENDER));
        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setType(EmailAddressType::RECIPIENT));
        $recipientAddress = (new Address())->setEmail('recipient@example.com');
        $originalEmail->addEmailAddress((new EmailAddressLink())
            ->setEmail($originalEmail)
            ->setAddress($recipientAddress)
            ->setType(EmailAddressType::RECIPIENT));

        $draft = new Email();

        $entityManager->expects(self::once())->method('persist');

        $copyMailboxAddressAsSender = new \ReflectionMethod(MailDraftService::class, 'copyMailboxAddressAsSender');
        $copyMailboxAddressAsSender->invoke($service, $originalEmail, $draft);

        self::assertCount(1, $draft->getEmailAddresses());
        self::assertSame($recipientAddress, $draft->getEmailAddresses()->first()?->getAddress());
        self::assertSame(EmailAddressType::SENDER, $draft->getEmailAddresses()->first()?->getType());
    }

    public function testPrivateHelpersNormalizeSubjectsAndAddresses(): void
    {
        $service = new MailDraftService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MailboxRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $this->createTranslatorStub(),
            new AllowAllLicenseEnforcement(),
        );

        $prefixSubject = new \ReflectionMethod(MailDraftService::class, 'prefixSubject');
        self::assertSame('Re', $prefixSubject->invoke($service, '  ', 'Re'));
        self::assertSame('Re Hello', $prefixSubject->invoke($service, 'Hello', 'Re'));
        self::assertSame('re Hello', $prefixSubject->invoke($service, 're Hello', 'Re'));

        $parseAddressList = new \ReflectionMethod(MailDraftService::class, 'parseAddressList');
        self::assertSame(
            ['one@example.com', 'Two@example.com'],
            $parseAddressList->invoke($service, " one@example.com;\nTwo@example.com, one@example.com "),
        );
    }

    public function testPrivateHelpersReplaceSingleAddressAndSyncAttachments(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $attachmentStorage = $this->createMock(AttachmentStorage::class);
        $service = new MailDraftService(
            $entityManager,
            $this->createStub(MailboxRepository::class),
            $this->createStub(FolderManager::class),
            $attachmentStorage,
            $this->createTranslatorStub(),
            new AllowAllLicenseEnforcement(),
        );

        $email = new Email();
        $existingSender = (new EmailAddressLink())
            ->setEmail($email)
            ->setAddress((new Address())->setEmail('old@example.com'))
            ->setType(EmailAddressType::SENDER);
        $retainedAttachment = (new \App\Entity\Attachment())
            ->setName('keep.txt')
            ->setMimeType('text/plain')
            ->setSize(4)
            ->setSha1('keep')
            ->setFilePath('keep.txt');
        $removedAttachment = (new \App\Entity\Attachment())
            ->setName('remove.txt')
            ->setMimeType('text/plain')
            ->setSize(6)
            ->setSha1('remove')
            ->setFilePath('remove.txt');
        $email->addEmailAddress($existingSender);
        $email->addEmailAttachment((new EmailAttachmentLink())->setEmail($email)->setAttachment($retainedAttachment));
        $email->addEmailAttachment((new EmailAttachmentLink())->setEmail($email)->setAttachment($removedAttachment));

        $addressRepository = $this->createMock(EntityRepository::class);
        $addressRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'new@example.com'])
            ->willReturn(null);

        $entityManager
            ->method('getRepository')
            ->with(Address::class)
            ->willReturn($addressRepository);
        $entityManager
            ->expects(self::atLeast(3))
            ->method('persist');
        $entityManager
            ->expects(self::atLeast(2))
            ->method('remove');

        $tempFile = tempnam(sys_get_temp_dir(), 'upload-');
        self::assertNotFalse($tempFile);
        file_put_contents($tempFile, 'payload');

        try {
            $uploadedFile = $this->createStub(UploadedFile::class);
            $uploadedFile->method('getPathname')->willReturn($tempFile);
            $uploadedFile->method('getClientOriginalName')->willReturn('new.txt');
            $uploadedFile->method('getFilename')->willReturn('tmp.txt');
            $uploadedFile->method('getMimeType')->willReturn('text/plain');
            $uploadedFile->method('getClientMimeType')->willReturn('text/plain');

            $attachmentStorage
                ->expects(self::once())
                ->method('store')
                ->with(self::isInstanceOf(\App\Entity\Attachment::class), 'payload')
                ->willReturn('stored/new.txt');

            $replaceAddresses = new \ReflectionMethod(MailDraftService::class, 'replaceAddresses');
            $replaceAddresses->invoke($service, $email, EmailAddressType::SENDER, ['new@example.com', 'ignored@example.com'], true);

            $syncAttachments = new \ReflectionMethod(MailDraftService::class, 'syncAttachments');
            $syncAttachments->invoke($service, $email, [(string) $retainedAttachment->getId()], [$uploadedFile]);
        } finally {
            @unlink($tempFile);
        }

        self::assertCount(1, array_filter(
            $email->getEmailAddresses()->toArray(),
            static fn (EmailAddressLink $link): bool => EmailAddressType::SENDER === $link->getType(),
        ));
        self::assertSame('new@example.com', $email->getEmailAddresses()->first()?->getAddress()?->getEmail());
        self::assertCount(2, $email->getEmailAttachments());
    }

    public function testPrivateHelpersSetMissingSenderNameSkipInvalidAttachmentAndRejectUnreadableUpload(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $service = new MailDraftService(
            $entityManager,
            $this->createStub(MailboxRepository::class),
            $this->createStub(FolderManager::class),
            $attachmentStorage,
            $this->createTranslatorStub(),
            new AllowAllLicenseEnforcement(),
        );

        $mailbox = (new Mailbox())
            ->setName('Primary')
            ->setEmail('primary@example.com');
        $existingAddress = (new Address())->setEmail('primary@example.com');

        $addressRepository = $this->createMock(EntityRepository::class);
        $addressRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'primary@example.com'])
            ->willReturn($existingAddress);

        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(Address::class)
            ->willReturn($addressRepository);
        $entityManager->expects(self::never())->method('persist');

        $resolveSenderAddress = new \ReflectionMethod(MailDraftService::class, 'resolveSenderAddress');
        self::assertSame($existingAddress, $resolveSenderAddress->invoke($service, $mailbox));
        self::assertSame('Primary', $existingAddress->getName());

        $source = new Email();
        $source->addEmailAttachment((new EmailAttachmentLink())->setEmail($source));
        $draft = new Email();

        $copyAttachments = new \ReflectionMethod(MailDraftService::class, 'copyAttachments');
        $copyAttachments->invoke($service, $source, $draft);
        self::assertCount(0, $draft->getEmailAttachments());

        $unreadableUpload = $this->createStub(UploadedFile::class);
        $unreadableUpload->method('getPathname')->willReturn('/path/that/does/not/exist');
        $unreadableUpload->method('getClientOriginalName')->willReturn('broken.txt');

        $syncAttachments = new \ReflectionMethod(MailDraftService::class, 'syncAttachments');

        try {
            set_error_handler(static fn (): bool => true);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('broken.txt');
            $syncAttachments->invoke($service, new Email(), [], [$unreadableUpload]);
        } finally {
            restore_error_handler();
        }
    }

    private function createTranslatorStub(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static function (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string {
                return match ($id) {
                    'email.reply_prefix' => 'Re',
                    'email.forward_prefix' => 'Fwd',
                    'email.reply_on', 'email.forward_on' => 'On '.$parameters['%date%'],
                    'email.wrote' => 'sender wrote',
                    'common.unknown' => 'unknown',
                    default => $id,
                };
            });

        return $translator;
    }
}

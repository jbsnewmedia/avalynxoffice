<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Service;

use App\Entity\Address;
use App\Entity\Attachment;
use App\Entity\Body;
use App\Entity\Email;
use App\Entity\EmailAddressLink;
use App\Entity\EmailAttachmentLink;
use App\Entity\EmailBodyLink;
use App\Entity\Enum\BodyContentType;
use App\Entity\Enum\EmailAddressType;
use App\Entity\Mailbox;
use App\Licensing\Service\LicenseEnforcementInterface;
use App\Licensing\Service\LicenseOperation;
use App\Repository\MailboxRepository;
use App\Service\Mail\AttachmentStorage;
use App\Service\Mail\FolderManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MailDraftService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailboxRepository $mailboxRepository,
        private FolderManager $folderManager,
        private AttachmentStorage $attachmentStorage,
        private TranslatorInterface $translator,
        private LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    public function createComposeDraft(): Email
    {
        $email = new Email();
        $email->setIsOutgoing(true);
        $email->setIsDraft(true);

        $mailbox = $this->mailboxRepository->findAll()[0] ?? null;
        if (null === $mailbox) {
            return $email;
        }

        $email->setMailbox($mailbox);
        $address = $this->resolveSenderAddress($mailbox);

        $link = new EmailAddressLink();
        $link->setEmail($email);
        $link->setAddress($address);
        $link->setType(EmailAddressType::SENDER);
        $email->addEmailAddress($link);

        return $email;
    }

    public function createReplyDraft(Email $originalEmail): Email
    {
        $draft = $this->createQuotedDraft(
            $originalEmail,
            $this->translator->trans('email.reply_prefix'),
            $this->translator->trans('email.reply_on', [
                '%date%' => $originalEmail->getReceivedAt()?->format('d.m.Y H:i') ?? $this->translator->trans('common.unknown'),
            ])
        );

        $this->attachReplyRecipients($originalEmail, $draft, false);
        $this->entityManager->flush();

        return $draft;
    }

    public function createReplyAllDraft(Email $originalEmail): Email
    {
        $draft = $this->createQuotedDraft(
            $originalEmail,
            $this->translator->trans('email.reply_prefix'),
            $this->translator->trans('email.reply_on', [
                '%date%' => $originalEmail->getReceivedAt()?->format('d.m.Y H:i') ?? $this->translator->trans('common.unknown'),
            ])
        );

        $this->attachReplyRecipients($originalEmail, $draft, true);
        $this->entityManager->flush();

        return $draft;
    }

    public function createForwardDraft(Email $originalEmail): Email
    {
        $draft = $this->createQuotedDraft(
            $originalEmail,
            $this->translator->trans('email.forward_prefix'),
            $this->translator->trans('email.forward_on', [
                '%date%' => $originalEmail->getReceivedAt()?->format('d.m.Y H:i') ?? $this->translator->trans('common.unknown'),
            ])
        );

        $this->copyAttachments($originalEmail, $draft);
        $this->entityManager->flush();

        return $draft;
    }

    /**
     * @param list<string>       $retainedAttachmentIds
     * @param list<UploadedFile> $uploadedAttachments
     */
    public function saveEmailFromForm(
        Email $email,
        ?string $from,
        string $to,
        string $cc,
        string $bcc,
        string $replyTo,
        string $content,
        bool $isDraft,
        ?\DateTimeImmutable $scheduledAt,
        array $retainedAttachmentIds = [],
        array $uploadedAttachments = [],
    ): void {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::MAIL_MODIFY);
        $this->replaceAddresses($email, EmailAddressType::SENDER, null === $from ? [] : [$from], true);
        $this->replaceAddresses($email, EmailAddressType::RECIPIENT, $this->parseAddressList($to));
        $this->replaceAddresses($email, EmailAddressType::CC, $this->parseAddressList($cc));
        $this->replaceAddresses($email, EmailAddressType::BCC, $this->parseAddressList($bcc));
        $this->replaceAddresses($email, EmailAddressType::REPLY_TO, $this->parseAddressList($replyTo));

        foreach ($email->getEmailBodies() as $bodyLink) {
            $email->removeEmailBody($bodyLink);
            $this->entityManager->remove($bodyLink);
        }

        $body = new Body();
        $body->setContent($content);
        $this->entityManager->persist($body);

        $bodyLink = new EmailBodyLink();
        $bodyLink->setEmail($email);
        $bodyLink->setBody($body);
        $bodyLink->setContentType(BodyContentType::TEXT);
        $this->entityManager->persist($bodyLink);
        $email->addEmailBody($bodyLink);

        $email->setPreviewBody(mb_substr($content, 0, 500));
        $email->setIsOutgoing(true);
        $email->setIsDraft($isDraft);
        $email->setIsSent(false);
        $email->setScheduledAt($scheduledAt);

        $this->syncAttachments($email, $retainedAttachmentIds, $uploadedAttachments);

        if ($email->getMailbox() instanceof Mailbox) {
            $this->folderManager->syncEmailSystemFolders($email);
        }

        $this->entityManager->persist($email);
        foreach ($email->getEmailAddresses() as $link) {
            $this->entityManager->persist($link);
        }

        $this->entityManager->flush();
    }

    private function createQuotedDraft(Email $originalEmail, string $prefix, string $quotedOnText): Email
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::MAIL_MODIFY);
        $draft = new Email();
        $draft->setSubject($this->prefixSubject($originalEmail->getSubject(), $prefix));
        $draft->setIsOutgoing(true);
        $draft->setIsDraft(true);

        $mailbox = $this->resolveMailbox($originalEmail);
        if ($mailbox instanceof Mailbox) {
            $draft->setMailbox($mailbox);
        }

        $this->copyMailboxAddressAsSender($originalEmail, $draft);
        $this->attachQuotedBody($draft, $originalEmail, $quotedOnText);

        if ($draft->getMailbox() instanceof Mailbox) {
            $this->folderManager->syncEmailSystemFolders($draft);
        }

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    private function resolveMailbox(Email $email): ?Mailbox
    {
        $mailbox = $email->getMailbox();
        if ($mailbox instanceof Mailbox) {
            return $mailbox;
        }

        foreach ($email->getEmailFolders() as $emailFolder) {
            $folder = $emailFolder->getFolder();
            if (null === $folder) {
                continue;
            }

            foreach ($folder->getMailboxLinks() as $mailboxLink) {
                return $mailboxLink->getMailbox();
            }
        }

        return null;
    }

    private function copyMailboxAddressAsSender(Email $originalEmail, Email $draft): void
    {
        $draftMailbox = $draft->getMailbox();
        foreach ($originalEmail->getEmailAddresses() as $link) {
            if (EmailAddressType::RECIPIENT !== $link->getType()) {
                continue;
            }

            $recipientAddress = $link->getAddress();
            if (!$recipientAddress instanceof Address) {
                continue;
            }

            if ($draftMailbox instanceof Mailbox) {
                foreach ($draftMailbox->getAddresses() as $mailboxAddress) {
                    if ($mailboxAddress instanceof Address && $mailboxAddress->getEmail() === $recipientAddress->getEmail()) {
                        $this->attachSenderAddress($draft, $mailboxAddress);

                        return;
                    }
                }
                if ($draftMailbox->getEmail() === $recipientAddress->getEmail()) {
                    $this->attachSenderAddress($draft, $recipientAddress);

                    return;
                }
            }

            $this->attachSenderAddress($draft, $recipientAddress);

            return;
        }

        if ($draftMailbox instanceof Mailbox) {
            $this->attachSenderAddress($draft, $this->resolveSenderAddress($draftMailbox));
        }
    }

    private function attachQuotedBody(Email $draft, Email $originalEmail, string $quotedOnText): void
    {
        $body = new Body();
        $body->setContent(
            "\n\n--- "
            .$quotedOnText
            .' '
            .$this->translator->trans('email.wrote')
            .' '
            .$originalEmail->getPreviewBody()
            ." ---\n\n"
            .$this->resolveOriginalContent($originalEmail)
        );
        $this->entityManager->persist($body);

        $bodyLink = new EmailBodyLink();
        $bodyLink->setEmail($draft);
        $bodyLink->setBody($body);
        $bodyLink->setContentType(BodyContentType::TEXT);
        $draft->addEmailBody($bodyLink);
        $this->entityManager->persist($bodyLink);
    }

    private function resolveOriginalContent(Email $originalEmail): string
    {
        foreach ($originalEmail->getEmailBodies() as $bodyLink) {
            $body = $bodyLink->getBody();
            if ($body instanceof Body && BodyContentType::TEXT === $bodyLink->getContentType()) {
                return $body->getContent();
            }
        }

        return (string) $originalEmail->getPreviewBody();
    }

    private function resolveSenderAddress(Mailbox $mailbox): Address
    {
        $preferredAddress = $mailbox->getPrimaryAddress();
        $email = $preferredAddress?->getEmail() ?? $mailbox->getEmail();
        $name = $preferredAddress?->getName() ?? $mailbox->getName();
        $address = $this->entityManager->getRepository(Address::class)->findOneBy(['email' => $email]);
        if (!$address instanceof Address) {
            $address = new Address();
            $address->setEmail($email);
            $address->setName($name);
            $this->entityManager->persist($address);
        } elseif (null === $address->getName()) {
            $address->setName($name);
        }

        return $address;
    }

    private function attachSenderAddress(Email $draft, Address $address): void
    {
        foreach ($draft->getEmailAddresses() as $existingLink) {
            if (EmailAddressType::SENDER === $existingLink->getType() && $existingLink->getAddress() === $address) {
                return;
            }
        }

        $newLink = new EmailAddressLink();
        $newLink->setEmail($draft);
        $newLink->setAddress($address);
        $newLink->setType(EmailAddressType::SENDER);
        $draft->addEmailAddress($newLink);
        $this->entityManager->persist($newLink);
    }

    private function prefixSubject(?string $subject, string $prefix): string
    {
        $trimmedSubject = trim((string) $subject);
        if ('' === $trimmedSubject) {
            return $prefix;
        }

        return str_starts_with(mb_strtolower($trimmedSubject), mb_strtolower($prefix))
            ? $trimmedSubject
            : sprintf('%s %s', $prefix, $trimmedSubject);
    }

    private function attachReplyRecipients(Email $originalEmail, Email $draft, bool $includeAll): void
    {
        $excludedEmails = [];
        foreach ($draft->getEmailAddresses() as $link) {
            $address = $link->getAddress();
            if ($address instanceof Address) {
                $excludedEmails[mb_strtolower($address->getEmail())] = true;
            }
        }

        $primaryRecipients = $this->collectAddressesByType($originalEmail, EmailAddressType::REPLY_TO);
        if ([] === $primaryRecipients) {
            $primaryRecipients = $this->collectAddressesByType($originalEmail, EmailAddressType::SENDER);
        }

        foreach ($primaryRecipients as $address) {
            $this->attachAddressIfMissing($draft, $address, EmailAddressType::RECIPIENT, $excludedEmails);
        }

        if (!$includeAll) {
            return;
        }

        foreach ($this->collectAddressesByType($originalEmail, EmailAddressType::RECIPIENT) as $address) {
            $this->attachAddressIfMissing($draft, $address, EmailAddressType::RECIPIENT, $excludedEmails);
        }

        foreach ($this->collectAddressesByType($originalEmail, EmailAddressType::CC) as $address) {
            $this->attachAddressIfMissing($draft, $address, EmailAddressType::CC, $excludedEmails);
        }
    }

    /**
     * @param array<string, true> $excludedEmails
     */
    private function attachAddressIfMissing(Email $draft, Address $address, EmailAddressType $type, array &$excludedEmails): void
    {
        $normalizedEmail = mb_strtolower($address->getEmail());
        if (isset($excludedEmails[$normalizedEmail])) {
            return;
        }

        $newLink = new EmailAddressLink();
        $newLink->setEmail($draft);
        $newLink->setAddress($address);
        $newLink->setType($type);
        $draft->addEmailAddress($newLink);
        $this->entityManager->persist($newLink);
        $excludedEmails[$normalizedEmail] = true;
    }

    /**
     * @return list<Address>
     */
    private function collectAddressesByType(Email $email, EmailAddressType $type): array
    {
        $addresses = [];
        foreach ($email->getEmailAddresses() as $link) {
            $address = $link->getAddress();
            if ($type !== $link->getType() || !$address instanceof Address) {
                continue;
            }

            $addresses[] = $address;
        }

        return $addresses;
    }

    private function copyAttachments(Email $source, Email $draft): void
    {
        foreach ($source->getEmailAttachments() as $attachmentLink) {
            $attachment = $attachmentLink->getAttachment();
            if (!$attachment instanceof Attachment) {
                continue;
            }

            $newLink = new EmailAttachmentLink();
            $newLink->setEmail($draft);
            $newLink->setAttachment($attachment);
            $draft->addEmailAttachment($newLink);
            $this->entityManager->persist($newLink);
        }
    }

    /**
     * @return list<string>
     */
    private function parseAddressList(string $value): array
    {
        $emails = preg_split('/[,;\r\n]+/', $value) ?: [];
        $normalized = [];
        $seen = [];
        foreach ($emails as $email) {
            $trimmed = trim($email);
            if ('' === $trimmed) {
                continue;
            }

            $lookupKey = mb_strtolower($trimmed);
            if (isset($seen[$lookupKey])) {
                continue;
            }

            $seen[$lookupKey] = true;
            $normalized[] = $trimmed;
        }

        return $normalized;
    }

    /**
     * @param list<string> $emails
     */
    private function replaceAddresses(Email $email, EmailAddressType $type, array $emails, bool $single = false): void
    {
        foreach ($email->getEmailAddresses() as $link) {
            if ($type !== $link->getType()) {
                continue;
            }

            $email->removeEmailAddress($link);
            $this->entityManager->remove($link);
        }

        $values = $single ? array_slice($emails, 0, 1) : $emails;
        foreach ($values as $addressEmail) {
            $address = $this->entityManager->getRepository(Address::class)->findOneBy(['email' => $addressEmail]);
            if (!$address instanceof Address) {
                $address = new Address();
                $address->setEmail($addressEmail);
                $this->entityManager->persist($address);
            }

            $link = new EmailAddressLink();
            $link->setEmail($email);
            $link->setAddress($address);
            $link->setType($type);
            $this->entityManager->persist($link);
            $email->addEmailAddress($link);
        }
    }

    /**
     * @param list<string>       $retainedAttachmentIds
     * @param list<UploadedFile> $uploadedAttachments
     */
    private function syncAttachments(Email $email, array $retainedAttachmentIds, array $uploadedAttachments): void
    {
        $retainedLookup = array_fill_keys($retainedAttachmentIds, true);
        foreach ($email->getEmailAttachments() as $attachmentLink) {
            $attachmentId = (string) ($attachmentLink->getAttachment()?->getId() ?? '');
            if ('' !== $attachmentId && isset($retainedLookup[$attachmentId])) {
                continue;
            }

            $email->removeEmailAttachment($attachmentLink);
            $this->entityManager->remove($attachmentLink);
        }

        foreach ($uploadedAttachments as $uploadedAttachment) {
            $payload = file_get_contents($uploadedAttachment->getPathname());
            if (false === $payload) {
                throw new \RuntimeException(sprintf('Attachment "%s" konnte nicht gelesen werden.', $uploadedAttachment->getClientOriginalName()));
            }

            $attachment = new Attachment();
            $attachment->setName('' !== trim($uploadedAttachment->getClientOriginalName()) ? $uploadedAttachment->getClientOriginalName() : $uploadedAttachment->getFilename());
            $attachment->setSize(strlen($payload));
            $attachment->setMimeType(($uploadedAttachment->getMimeType() ?: $uploadedAttachment->getClientMimeType()) ?: 'application/octet-stream');
            $attachment->setSha1(sha1($payload));
            $attachment->setFilePath($this->attachmentStorage->store($attachment, $payload));
            $this->entityManager->persist($attachment);

            $link = new EmailAttachmentLink();
            $link->setEmail($email);
            $link->setAttachment($attachment);
            $email->addEmailAttachment($link);
            $this->entityManager->persist($link);
        }
    }
}

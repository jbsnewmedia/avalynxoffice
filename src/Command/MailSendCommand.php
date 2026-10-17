<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Enum\BodyContentType;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Entity\MailboxConnector;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\SmtpMailerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email as SymfonyEmail;

#[AsCommand(
    name: 'app:mail:send',
    description: 'Sends all emails that are marked for sending.'
)]
class MailSendCommand extends Command
{
    public function __construct(
        private readonly EmailRepository $emailRepo,
        private readonly EntityManagerInterface $entityManager,
        private readonly FolderManager $folderManager,
        private readonly AttachmentStorage $attachmentStorage,
        private readonly SmtpMailerFactory $mailerFactory,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pendingEmails = $this->emailRepo->findBy([
            'isOutgoing' => true,
            'isSent' => false,
            'isDraft' => false,
            'isSending' => false,
        ]);
        $pendingEmails = array_values(array_filter(
            $pendingEmails,
            static fn (Email $email): bool => !$email->isLicenseHold()
        ));
        if (empty($pendingEmails)) {
            $output->writeln('No pending emails found to send.');

            return Command::SUCCESS;
        }

        if ($this->licenseEnforcement->isReadOnly()) {
            $this->holdPendingEmails($pendingEmails, $output);

            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable();
        foreach ($pendingEmails as $email) {
            if ($email->getScheduledAt() && $email->getScheduledAt() > $now) {
                continue;
            }
            if ($this->licenseEnforcement->isReadOnly()) {
                $this->holdPendingEmails([$email], $output);

                continue;
            }
            $this->sendEmail($email, $output);
        }

        return Command::SUCCESS;
    }

    /**
     * Marks the given messages as paused because of the license policy.
     * The messages are preserved: they are only sent again after the license
     * has been restored and they have been explicitly released with
     * "app:license:release-mail".
     *
     * @param list<Email> $emails
     */
    private function holdPendingEmails(array $emails, OutputInterface $output): void
    {
        foreach ($emails as $email) {
            $email->setLicenseHold(true);
        }
        $this->entityManager->flush();
        $output->writeln(sprintf(
            '[LICENSE] AvalynxOffice is in restricted mode. %d pending message(s) were held and not sent. After the license is restored, they can be released with "app:license:release-mail".',
            count($emails),
        ));
    }

    private function sendEmail(Email $email, OutputInterface $output): void
    {
        $output->writeln(sprintf('Sending mail: %s (ID: %s)', $email->getSubject(), $email->getId()));
        $mailbox = $email->getMailbox();
        $connector = $mailbox?->getPrimaryConnector();
        if (!$mailbox || !($connector instanceof MailboxConnector) || !$connector->getSmtpHost()) {
            $output->writeln('  [ERROR] No SMTP configuration found for this mail/mailbox.');

            return;
        }
        $email->setIsSending(true);
        $this->entityManager->flush();
        try {
            $mailer = $this->mailerFactory->fromConnector($connector);
            $symfonyEmail = new SymfonyEmail();
            $symfonyEmail->subject((string) $email->getSubject());
            foreach ($email->getEmailAddresses() as $link) {
                $addr = $link->getAddress();
                if (!$addr) {
                    continue;
                }
                $address = new SymfonyAddress($addr->getEmail(), $addr->getName() ?? '');
                match ($link->getType()) {
                    EmailAddressType::SENDER => $symfonyEmail->addFrom($address),
                    EmailAddressType::RECIPIENT => $symfonyEmail->addTo($address),
                    EmailAddressType::CC => $symfonyEmail->addCc($address),
                    EmailAddressType::BCC => $symfonyEmail->addBcc($address),
                    EmailAddressType::REPLY_TO => $symfonyEmail->addReplyTo($address),
                };
            }
            foreach ($email->getEmailBodies() as $bodyLink) {
                $body = $bodyLink->getBody();
                if (!$body) {
                    continue;
                }
                $content = $body->getContent();
                if (BodyContentType::HTML === $bodyLink->getContentType()) {
                    $symfonyEmail->html($content);
                } else {
                    $symfonyEmail->text($content);
                }
            }
            foreach ($email->getEmailAttachments() as $attachmentLink) {
                $attachment = $attachmentLink->getAttachment();
                if (null === $attachment) {
                    continue;
                }

                $symfonyEmail->attachFromPath(
                    $this->attachmentStorage->resolveAbsolutePath($attachment->getFilePath()),
                    $attachment->getName(),
                    $attachment->getMimeType()
                );
            }
            $mailer->send($symfonyEmail);
            $email->setIsSent(true);
            $email->setSentAt(new \DateTimeImmutable());
            if ($email->getMailbox()) {
                $this->folderManager->syncEmailSystemFolders($email);
            }
            $output->writeln('  [OK] Mail sent successfully.');
        } catch (\Exception $e) {
            $output->writeln(sprintf('  [ERROR] Failed to send: %s', $e->getMessage()));
        } finally {
            $email->setIsSending(false);
            $this->entityManager->flush();
        }
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Mail;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Enum\BodyContentType;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Repository\ConnectorRepository;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Service\ConnectorPluginRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Sends all emails that are marked for sending: outgoing, not sent, not a
 * draft and not currently being sent by another process. Scheduled mails
 * (scheduled_at in the future) wait until their time has come.
 *
 * The service is the single sending pipeline: the "app:mail:send" command
 * and the sync (daemon and cron triggered passes) both call it, so pending
 * mails are delivered without a dedicated sender process.
 */
final readonly class MailSendService
{
    public function __construct(
        private EmailRepository $emailRepo,
        private EntityManagerInterface $entityManager,
        private FolderManager $folderManager,
        private AttachmentStorage $attachmentStorage,
        private ConnectorPluginRegistry $pluginRegistry,
        private ConnectorRepository $connectorRepository,
        private LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    /**
     * Sends every pending mail whose scheduled time (if any) has passed.
     * Returns the human-readable progress lines, one per processed mail
     * plus license holds — an empty list means nothing was pending.
     *
     * @return list<string>
     */
    public function sendPending(): array
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
        if ([] === $pendingEmails) {
            return [];
        }

        if ($this->licenseEnforcement->isReadOnly()) {
            return [$this->holdPendingEmails($pendingEmails)];
        }

        $messages = [];
        $now = new \DateTimeImmutable();
        foreach ($pendingEmails as $email) {
            if ($email->getScheduledAt() && $email->getScheduledAt() > $now) {
                continue;
            }
            // Read-only kann sich während eines Laufs aktivieren (z. B. wenn
            // das Lizenz-Limit mitten im Prozess greift): pro Mail neu
            // bewerten, damit die restlichen Mails gehalten werden.
            if ($this->shouldHoldDuringRun($email)) {
                $messages[] = $this->holdPendingEmails([$email]);

                continue;
            }
            $messages = [...$messages, ...$this->sendEmail($email)];
        }

        return $messages;
    }

    /**
     * Read-only kann sich während eines Laufs aktivieren (z. B. wenn das
     * Lizenz-Limit mitten im Prozess greift): der Status wird deshalb für
     * jede Mail neu bewertet.
     */
    private function shouldHoldDuringRun(Email $email): bool
    {
        return $email->isLicenseHold() || $this->licenseEnforcement->isReadOnly();
    }

    /**
     * Marks the given messages as paused because of the license policy.
     * The messages are preserved: they are only sent again after the license
     * has been restored and they have been explicitly released with
     * "app:license:release-mail".
     *
     * @param list<Email> $emails
     */
    private function holdPendingEmails(array $emails): string
    {
        foreach ($emails as $email) {
            $email->setLicenseHold(true);
        }
        $this->entityManager->flush();

        return sprintf(
            '[LICENSE] AvalynxOffice is in restricted mode. %d pending message(s) were held and not sent. After the license is restored, they can be released with "app:license:release-mail".',
            count($emails),
        );
    }

    /**
     * @return list<string>
     */
    private function sendEmail(Email $email): array
    {
        $messages = [sprintf('Sending mail: %s (ID: %s)', $email->getSubject(), $email->getId())];
        $connector = $this->resolveConnectorForEmail($email);
        if (!$connector instanceof Connector) {
            return [...$messages, '  [ERROR] No SMTP configuration found for this mail/account.'];
        }
        $mailer = $this->pluginRegistry->createMailer($connector);
        if (!$mailer instanceof MailerInterface) {
            return [...$messages, '  [ERROR] No SMTP configuration found for this mail/account.'];
        }
        $email->setIsSending(true);
        $this->entityManager->flush();
        try {
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
            if ($email->getAccount()) {
                $this->folderManager->syncEmailSystemFolders($email);
            }
            $messages[] = '  [OK] Mail sent successfully.';
        } catch (\Exception $e) {
            $messages[] = sprintf('  [ERROR] Failed to send: %s', $e->getMessage());
        } finally {
            $email->setIsSending(false);
            $this->entityManager->flush();
        }

        return $messages;
    }

    /**
     * Resolves the connector that must deliver the message: the domain of
     * the sender address decides (fallback: the domain of the account's
     * main address).
     */
    private function resolveConnectorForEmail(Email $email): ?Connector
    {
        $senderAddress = null;
        foreach ($email->getEmailAddresses() as $link) {
            if (EmailAddressType::SENDER === $link->getType()) {
                $senderAddress = $link->getAddress()?->getEmail();
                break;
            }
        }
        $senderAddress ??= $email->getAccount()?->getEmail();
        if (!is_string($senderAddress) || '' === $senderAddress) {
            return null;
        }

        $domain = MailRoutingService::domainOf($senderAddress);
        if (null === $domain) {
            return null;
        }

        return $this->connectorRepository->findOneBy(['domain' => $domain]);
    }
}

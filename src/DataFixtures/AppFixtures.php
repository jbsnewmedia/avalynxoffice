<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\DataFixtures;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\Body;
use AvalynxOffice\Entity\CalendarEvent;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailAddressLink;
use AvalynxOffice\Entity\EmailAttachmentLink;
use AvalynxOffice\Entity\EmailBodyLink;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\BodyContentType;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\FolderMailbox;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\MailboxConnector;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Factory\AddressFactory;
use AvalynxOffice\Factory\AttachmentFactory;
use AvalynxOffice\Factory\BodyFactory;
use AvalynxOffice\Factory\EmailFactory;
use AvalynxOffice\Factory\FolderFactory;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Persistence\Proxy;

class AppFixtures extends Fixture
{
    /** @param Proxy<mixed>|mixed $proxy */
    private static function unproxy(mixed $proxy): mixed
    {
        if ($proxy instanceof Proxy) {
            return $proxy->_real();
        }

        return $proxy;
    }

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly FolderManager $folderManager,
        private readonly AttachmentStorage $attachmentStorage,
    ) {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $extraFolders = array_map(self::unproxy(...), FolderFactory::createMany(8));
        $mailboxes = [
            $this->createMailbox(
                $manager,
                name: 'Info',
                email: 'info@example.com',
                connectorName: 'Info Connector',
                connectorEmail: 'info@connector.example',
                assignedAddresses: ['info@firma.example'],
                hostPrefix: 'info'
            ),
            $this->createMailbox(
                $manager,
                name: 'Office',
                email: 'office@example.com',
                connectorName: 'Buero Connector',
                connectorEmail: 'buero@connector.example',
                assignedAddresses: [
                    'j.schwind@example.com',
                    'e.schwind@example.com',
                    'es@example.com',
                    'js@example.com',
                ],
                hostPrefix: 'buero'
            ),
            $this->createMailbox(
                $manager,
                name: 'Support',
                email: 'support@example.com',
                connectorName: 'Support Connector',
                connectorEmail: 'support@connector.example',
                assignedAddresses: ['help@example.com', 'service@example.com'],
                hostPrefix: 'support'
            ),
        ];

        /** @var array<string, array{mailbox: Folder, inbox: Folder, outbox: Folder, drafts: Folder, others: list<Folder>}> $foldersByMailbox */
        $foldersByMailbox = [];
        foreach ($mailboxes as $mailbox) {
            $this->folderManager->ensureDefaultFolders($mailbox);

            $others = [];
            $pick = self::randomUniqueSubset($extraFolders, random_int(1, 3));
            $order = 0;
            foreach ($pick as $folder) {
                \assert($folder instanceof Folder);
                $link = new FolderMailbox();
                $link->setMailbox($mailbox);
                $link->setFolder($folder);
                $link->setSortOrder($order++);
                $manager->persist($link);
                $mailbox->getFolderLinks()->add($link);
                $others[] = $folder;
            }

            $foldersByMailbox[(string) $mailbox->getId()] = [
                'mailbox' => $this->folderManager->ensureSystemFolder($mailbox, FolderFunction::MAILBOX),
                'inbox' => $this->folderManager->ensureSystemFolder($mailbox, FolderFunction::INBOX),
                'outbox' => $this->folderManager->ensureSystemFolder($mailbox, FolderFunction::OUTBOX),
                'drafts' => $this->folderManager->ensureSystemFolder($mailbox, FolderFunction::DRAFTS),
                'others' => $others,
            ];
        }

        $user = new User();
        $user->setEmail('admin@example.com');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, 'admin'));
        $user->setLastAuthAt(new \DateTimeImmutable('now'));
        foreach ($mailboxes as $mailbox) {
            $user->addMailbox($mailbox);
        }
        $manager->persist($user);

        $addresses = array_map(self::unproxy(...), AddressFactory::createMany(25));
        $addresses = array_values(array_filter($addresses, static fn (mixed $address): bool => $address instanceof Address));
        foreach ($mailboxes as $mailbox) {
            $addresses[] = $this->findOrCreateAddress($manager, $mailbox->getEmail(), $mailbox->getName());
            foreach ($mailbox->getAddresses() as $address) {
                $addresses[] = $address;
            }
        }
        $addresses = $this->deduplicateAddresses($addresses);

        foreach ($mailboxes as $mailbox) {
            foreach (array_slice($mailbox->getAddresses()->toArray(), 0, 2) as $contactAddress) {
                $contactAddress->setIsContact(true);
                $contactAddress->setFirstname($this->contactNamePart($contactAddress->getName(), 0));
                $contactAddress->setLastname($this->contactNamePart($contactAddress->getName(), 1));
                $contactAddress->setCompany('JBS Newmedia');
            }
        }

        $bodies = BodyFactory::createMany(40);

        $this->seedCalendarEvents($manager, $mailboxes[0]);
        $attachments = array_map(self::unproxy(...), AttachmentFactory::createMany(30));
        $attachments = array_values(array_filter($attachments, static fn (mixed $attachment): bool => $attachment instanceof Attachment));
        foreach ($attachments as $attachment) {
            $payload = $this->createAttachmentPayload($attachment);
            $attachment->setSize(strlen($payload));
            $attachment->setSha1(sha1($payload));
            $attachment->setFilePath($this->attachmentStorage->store($attachment, $payload));
        }
        $emails = EmailFactory::createMany(1000);
        foreach ($emails as $emailProxy) {
            /** @var Email $email */
            $email = self::unproxy($emailProxy);
            $mailbox = self::randomElement($mailboxes);
            \assert($mailbox instanceof Mailbox);
            $email->setMailbox($mailbox);
            $mailboxAddresses = $this->mailboxAddresses($manager, $mailbox);
            $externalAddresses = array_values(array_filter(
                $addresses,
                static fn (Address $address): bool => !in_array($address->getEmail(), array_map(static fn (Address $mailboxAddress): string => $mailboxAddress->getEmail(), $mailboxAddresses), true)
            ));
            $mbId = (string) $mailbox->getId();
            $isOutgoing = random_int(0, 100) < 35;
            $email->setIsOutgoing($isOutgoing);
            $targetFolders = [];
            if ($isOutgoing) {
                $rand = random_int(0, 100);
                if ($rand < 80) {
                    $email->setSentAt(new \DateTimeImmutable('-'.random_int(0, 30).' days'));
                    $email->setIsSent(true);
                    $email->setIsDraft(false);
                    $targetFolders[] = $foldersByMailbox[$mbId]['outbox'];
                } elseif ($rand < 95) {
                    $email->setSentAt(null);
                    $email->setIsSent(false);
                    $email->setIsDraft(true);
                    $targetFolders[] = $foldersByMailbox[$mbId]['drafts'];
                } else {
                    $email->setSentAt(null);
                    $email->setIsSent(false);
                    $email->setIsDraft(false);
                    $targetFolders[] = $foldersByMailbox[$mbId]['outbox'];
                }
                $email->setIsRead(true);
                $sender = self::randomElement($mailboxAddresses);
                $this->linkAddress($manager, $email, $sender, EmailAddressType::SENDER);
                $recipients = self::randomUniqueSubset($externalAddresses, random_int(1, 3));
                foreach ($recipients as $recipient) {
                    $this->linkAddress($manager, $email, $recipient, EmailAddressType::RECIPIENT);
                }
            } else {
                $email->setReceivedAt(new \DateTimeImmutable('-'.random_int(0, 30).' days'));
                $email->setIsRead(random_int(0, 100) < 40);
                $targetFolders[] = $foldersByMailbox[$mbId]['mailbox'];
                $targetFolders[] = $foldersByMailbox[$mbId]['inbox'];
                $externalAddresses = [] !== $externalAddresses ? $externalAddresses : $mailboxAddresses;
                $sender = self::randomElement($externalAddresses);
                $this->linkAddress($manager, $email, $sender, EmailAddressType::SENDER);
                $recipientMax = max(1, count($mailboxAddresses));
                $recipients = self::randomUniqueSubset($mailboxAddresses, random_int(1, min(2, $recipientMax)));
                foreach ($recipients as $recipient) {
                    $this->linkAddress($manager, $email, $recipient, EmailAddressType::RECIPIENT);
                }
            }
            if (random_int(0, 100) < 20 && !empty($foldersByMailbox[$mbId]['others'])) {
                $targetFolders[] = self::randomElement($foldersByMailbox[$mbId]['others']);
            }
            foreach (array_unique($targetFolders, SORT_REGULAR) as $folder) {
                \assert($folder instanceof Folder);
                $ef = new EmailFolder();
                $ef->setEmail($email);
                $ef->setFolder($folder);
                $manager->persist($ef);
            }
            if (random_int(0, 100) < 30) {
                $bcc = self::randomElement($externalAddresses);
                $this->linkAddress($manager, $email, $bcc, EmailAddressType::BCC);
            }
            if (random_int(0, 100) < 20) {
                $reply = self::randomElement($mailboxAddresses);
                $this->linkAddress($manager, $email, $reply, EmailAddressType::REPLY_TO);
            }
            $email->setIsFavorite(random_int(0, 100) < 25);
            $email->setIsImportant(random_int(0, 100) < 20);
            $textBody = self::randomElement($bodies);
            $this->linkBody($manager, $email, $textBody, BodyContentType::TEXT);
            if (random_int(0, 100) < 60) {
                $htmlBody = self::randomElement($bodies);
                $this->linkBody($manager, $email, $htmlBody, BodyContentType::HTML);
            }
            $pickAttachments = self::randomUniqueSubset($attachments, random_int(0, 3));
            foreach ($pickAttachments as $attachmentProxy) {
                /** @var Attachment $attachment */
                $attachment = self::unproxy($attachmentProxy);
                $link = new EmailAttachmentLink();
                $link->setEmail($email);
                $link->setAttachment($attachment);
                $manager->persist($link);
            }
        }
        $manager->flush();
    }

    private function createAttachmentPayload(Attachment $attachment): string
    {
        $maxBytes = max(1, $this->attachmentStorage->getMaxBytes());
        $targetSize = $maxBytes <= 512 ? $maxBytes : random_int(512, $maxBytes);
        $mimeType = strtolower($attachment->getMimeType());

        if ('text/csv' === $mimeType) {
            $line = "name,email\nMax Mustermann,max@example.com\n";

            return substr(str_repeat($line, (int) ceil($targetSize / strlen($line))), 0, $targetSize);
        }

        if (str_starts_with($mimeType, 'text/')) {
            $line = sprintf("Fixture attachment %s\n", $attachment->getName());

            return substr(str_repeat($line, (int) ceil($targetSize / strlen($line))), 0, $targetSize);
        }

        $prefix = match ($mimeType) {
            'application/pdf' => "%PDF-1.4\n% fixture\n",
            'application/zip' => "PK\x03\x04",
            default => "ATTACHMENT\n",
        };

        if ($targetSize <= strlen($prefix)) {
            return substr($prefix, 0, $targetSize);
        }

        $remainingBytes = max(1, $targetSize - strlen($prefix));

        return $prefix.random_bytes($remainingBytes);
    }

    /**
     * @param array<string> $assignedAddresses
     */
    private function createMailbox(
        ObjectManager $manager,
        string $name,
        string $email,
        string $connectorName,
        string $connectorEmail,
        array $assignedAddresses,
        string $hostPrefix,
    ): Mailbox {
        $mailbox = new Mailbox();
        $mailbox->setName($name);
        $mailbox->setEmail($email);
        $manager->persist($mailbox);

        $connector = new MailboxConnector();
        $connector->setName($connectorName);
        $connector->setEmail($connectorEmail);
        $connector->setPop3Host(sprintf('pop3.%s.example', $hostPrefix));
        $connector->setPop3Port(995);
        $connector->setPop3Username($connectorEmail);
        $connector->setPop3Password('secret');
        $connector->setPop3Security('ssl');
        $connector->setPop3DeleteAfterFetch(false);
        $connector->setSmtpHost(sprintf('smtp.%s.example', $hostPrefix));
        $connector->setSmtpPort(465);
        $connector->setSmtpUsername($connectorEmail);
        $connector->setSmtpPassword('secret');
        $connector->setSmtpSecurity('ssl');
        $connector->setIsPrimary(true);
        $mailbox->addConnector($connector);
        $manager->persist($connector);

        foreach ($assignedAddresses as $assignedAddress) {
            $mailbox->addAddress($this->findOrCreateAddress($manager, $assignedAddress));
        }

        $manager->flush();

        return $mailbox;
    }

    private function findOrCreateAddress(ObjectManager $manager, string $email, ?string $name = null): Address
    {
        $repo = $manager->getRepository(Address::class);
        $existing = $repo->findOneBy(['email' => $email]);
        if ($existing instanceof Address) {
            if (null !== $name && null === $existing->getName()) {
                $existing->setName($name);
            }

            return $existing;
        }

        $address = new Address();
        $address->setEmail($email);
        $address->setName($name);
        $manager->persist($address);

        return $address;
    }

    /**
     * @return array<int, Address>
     */
    private function mailboxAddresses(ObjectManager $manager, Mailbox $mailbox): array
    {
        $addresses = [$this->findOrCreateAddress($manager, $mailbox->getEmail(), $mailbox->getName())];
        foreach ($mailbox->getAddresses() as $address) {
            if ($address instanceof Address) {
                $addresses[] = $address;
            }
        }

        return $this->deduplicateAddresses($addresses);
    }

    /**
     * @param array<int, Address> $addresses
     *
     * @return array<int, Address>
     */
    private function deduplicateAddresses(array $addresses): array
    {
        $byEmail = [];
        foreach ($addresses as $address) {
            $byEmail[strtolower($address->getEmail())] = $address;
        }

        return array_values($byEmail);
    }

    /**
     * Splits a display name into a contact name part; index 0 is the first
     * name, index 1 the last name.
     */
    private function contactNamePart(?string $name, int $index): ?string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];

        return $parts[$index] ?? null;
    }

    /**
     * Seeds a handful of calendar events around the current date so the
     * calendar module has content in development environments.
     */
    private function seedCalendarEvents(ObjectManager $manager, Mailbox $mailbox): void
    {
        $now = new \DateTimeImmutable();

        $events = [
            ['title' => 'Server maintenance window', 'location' => 'Data center', 'description' => 'Check regular updates and backups.', 'start' => $now->modify('first day of this month')->setTime(9, 0), 'end' => $now->modify('first day of this month')->setTime(11, 0), 'allDay' => false],
            ['title' => 'Team meeting', 'location' => 'Mainz office', 'description' => null, 'start' => $now->modify('monday this week')->setTime(10, 0), 'end' => $now->modify('monday this week')->setTime(11, 30), 'allDay' => false],
            ['title' => 'Kundentermin', 'location' => 'beim Kunden', 'description' => 'Vorstellung der neuen Exchange-Anbindung.', 'start' => $now->modify('friday this week')->setTime(14, 0), 'end' => $now->modify('friday this week')->setTime(15, 0), 'allDay' => false],
            ['title' => 'Urlaub', 'location' => null, 'description' => null, 'start' => $now->modify('first day of next month')->setTime(0, 0), 'end' => null, 'allDay' => true],
            ['title' => 'Retrospective', 'location' => 'Online', 'description' => 'Monthly retrospective with the whole team.', 'start' => $now->modify('last day of next month')->setTime(16, 0), 'end' => $now->modify('last day of next month')->setTime(17, 0), 'allDay' => false],
            ['title' => 'Annual planning', 'location' => 'Mainz office', 'description' => 'Planning for the next quarter.', 'start' => $now->modify('first day of last month')->setTime(9, 0), 'end' => $now->modify('first day of last month')->setTime(12, 0), 'allDay' => false],
        ];

        foreach ($events as $definition) {
            $event = new CalendarEvent()
                ->setTitle($definition['title'])
                ->setLocation($definition['location'])
                ->setDescription($definition['description'])
                ->setStartAt($definition['start'])
                ->setEndAt($definition['end'])
                ->setIsAllDay($definition['allDay'])
                ->setMailbox($mailbox);
            $manager->persist($event);
        }

        $manager->flush();
    }

    private function linkAddress(ObjectManager $manager, Email $email, mixed $addressProxy, EmailAddressType $type): void
    {
        /** @var Address $address */
        $address = self::unproxy($addressProxy);
        $link = new EmailAddressLink();
        $link->setEmail($email);
        $link->setAddress($address);
        $link->setType($type);
        $manager->persist($link);
    }

    private function linkBody(ObjectManager $manager, Email $email, mixed $bodyProxy, BodyContentType $type): void
    {
        /** @var Body $body */
        $body = self::unproxy($bodyProxy);
        $link = new EmailBodyLink();
        $link->setEmail($email);
        $link->setBody($body);
        $link->setContentType($type);
        $manager->persist($link);
    }

    /**
     * @param array<mixed> $items
     *
     * @return array<mixed>
     */
    private static function randomUniqueSubset(array $items, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $count = min($count, count($items));
        $keys = array_rand($items, $count);
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        return array_values(array_intersect_key($items, array_flip($keys)));
    }

    /**
     * @param array<mixed> $items
     */
    private static function randomElement(array $items): mixed
    {
        return $items[array_rand($items)];
    }
}

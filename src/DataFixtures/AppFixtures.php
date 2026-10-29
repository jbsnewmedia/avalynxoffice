<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\DataFixtures;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAlias;
use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\Body;
use AvalynxOffice\Entity\CalendarEvent;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\ConnectorConfig;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailAddressLink;
use AvalynxOffice\Entity\EmailAttachmentLink;
use AvalynxOffice\Entity\EmailBodyLink;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\BodyContentType;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\FolderAccount;
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
        $connectorExample = $this->createConnector(
            $manager,
            name: 'Example',
            domain: 'example.com',
            hostPrefix: 'example',
            defaultAccount: null,
        );
        $connectorFirma = $this->createConnector(
            $manager,
            name: 'Firma',
            domain: 'firma.example',
            hostPrefix: 'firma',
            defaultAccount: null,
        );
        $accounts = [
            $this->createAccount(
                $manager,
                name: 'Info',
                email: 'info@example.com',
                aliases: [
                    [$connectorFirma, 'info'],
                ],
            ),
            $this->createAccount(
                $manager,
                name: 'Office',
                email: 'office@example.com',
                aliases: [
                    [$connectorExample, 'j.schwind'],
                    [$connectorExample, 'e.schwind'],
                    [$connectorExample, 'es'],
                    [$connectorExample, 'js'],
                ],
            ),
            $this->createAccount(
                $manager,
                name: 'Support',
                email: 'support@example.com',
                aliases: [
                    [$connectorExample, 'help'],
                    [$connectorExample, 'service'],
                ],
            ),
        ];
        $connectorExample->setDefaultAccount($accounts[2]);
        $connectorFirma->setDefaultAccount($accounts[0]);
        $manager->flush();

        /** @var array<string, array{account: Folder, inbox: Folder, outbox: Folder, scheduled: Folder, drafts: Folder, others: list<Folder>}> $foldersByAccount */
        $foldersByAccount = [];
        foreach ($accounts as $account) {
            $this->folderManager->ensureDefaultFolders($account);

            $others = [];
            $pick = self::randomUniqueSubset($extraFolders, random_int(1, 3));
            $order = 0;
            foreach ($pick as $folder) {
                \assert($folder instanceof Folder);
                $link = new FolderAccount();
                $link->setAccount($account);
                $link->setFolder($folder);
                $link->setSortOrder($order++);
                $manager->persist($link);
                $account->getFolderLinks()->add($link);
                $others[] = $folder;
            }

            $foldersByAccount[(string) $account->getId()] = [
                'account' => $this->folderManager->ensureSystemFolder($account, FolderFunction::MAILBOX),
                'inbox' => $this->folderManager->ensureSystemFolder($account, FolderFunction::INBOX),
                'outbox' => $this->folderManager->ensureSystemFolder($account, FolderFunction::OUTBOX),
                'scheduled' => $this->folderManager->ensureSystemFolder($account, FolderFunction::SCHEDULED),
                'drafts' => $this->folderManager->ensureSystemFolder($account, FolderFunction::DRAFTS),
                'others' => $others,
            ];
        }

        $user = new User();
        $user->setEmail('admin@example.com');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, 'admin'));
        $user->setLastAuthAt(new \DateTimeImmutable('now'));
        foreach ($accounts as $account) {
            $user->addAccount($account);
        }
        $manager->persist($user);

        $addresses = array_map(self::unproxy(...), AddressFactory::createMany(25));
        $addresses = array_values(array_filter($addresses, static fn (mixed $address): bool => $address instanceof Address));
        foreach ($accounts as $account) {
            $addresses[] = $this->findOrCreateAddress($manager, $account->getEmail(), $account->getName());
            foreach ($account->getAddresses() as $address) {
                $addresses[] = $address;
            }
        }
        $addresses = $this->deduplicateAddresses($addresses);

        foreach ($accounts as $account) {
            foreach (array_slice($account->getAddresses()->toArray(), 0, 2) as $contactAddress) {
                $contactAddress->setIsContact(true);
                $contactAddress->setFirstname($this->contactNamePart($contactAddress->getName(), 0));
                $contactAddress->setLastname($this->contactNamePart($contactAddress->getName(), 1));
                $contactAddress->setCompany('JBS Newmedia');
            }
        }

        $bodies = BodyFactory::createMany(40);

        $this->seedCalendarEvents($manager, $accounts[0]);
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
            $account = self::randomElement($accounts);
            \assert($account instanceof Account);
            $email->setAccount($account);
            $accountAddresses = $this->accountAddresses($manager, $account);
            $externalAddresses = array_values(array_filter(
                $addresses,
                static fn (Address $address): bool => !in_array($address->getEmail(), array_map(static fn (Address $accountAddress): string => $accountAddress->getEmail(), $accountAddresses), true)
            ));
            $mbId = (string) $account->getId();
            $isOutgoing = random_int(0, 100) < 35;
            $email->setIsOutgoing($isOutgoing);
            // Postfach contains ALL mails; the second folder marks the special
            // folder the mail belongs in.
            $targetFolders = [$foldersByAccount[$mbId]['account']];
            if ($isOutgoing) {
                $rand = random_int(0, 100);
                if ($rand < 80) {
                    $email->setSentAt(new \DateTimeImmutable('-'.random_int(0, 30).' days'));
                    $email->setIsSent(true);
                    $email->setIsDraft(false);
                    $targetFolders[] = $foldersByAccount[$mbId]['outbox'];
                } elseif ($rand < 95) {
                    $email->setSentAt(null);
                    $email->setIsSent(false);
                    $email->setIsDraft(true);
                    $targetFolders[] = $foldersByAccount[$mbId]['drafts'];
                } else {
                    $email->setSentAt(null);
                    $email->setIsSent(false);
                    $email->setIsDraft(false);
                    $targetFolders[] = $foldersByAccount[$mbId]['scheduled'];
                }
                $email->setIsRead(true);
                $sender = self::randomElement($accountAddresses);
                $this->linkAddress($manager, $email, $sender, EmailAddressType::SENDER);
                $recipients = self::randomUniqueSubset($externalAddresses, random_int(1, 3));
                foreach ($recipients as $recipient) {
                    $this->linkAddress($manager, $email, $recipient, EmailAddressType::RECIPIENT);
                }
            } else {
                $email->setReceivedAt(new \DateTimeImmutable('-'.random_int(0, 30).' days'));
                $email->setIsRead(random_int(0, 100) < 40);
                $targetFolders[] = $foldersByAccount[$mbId]['inbox'];
                $externalAddresses = [] !== $externalAddresses ? $externalAddresses : $accountAddresses;
                $sender = self::randomElement($externalAddresses);
                $this->linkAddress($manager, $email, $sender, EmailAddressType::SENDER);
                $recipientMax = max(1, count($accountAddresses));
                $recipients = self::randomUniqueSubset($accountAddresses, random_int(1, min(2, $recipientMax)));
                foreach ($recipients as $recipient) {
                    $this->linkAddress($manager, $email, $recipient, EmailAddressType::RECIPIENT);
                }
            }
            if (random_int(0, 100) < 20 && !empty($foldersByAccount[$mbId]['others'])) {
                $targetFolders[] = self::randomElement($foldersByAccount[$mbId]['others']);
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
                $reply = self::randomElement($accountAddresses);
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
     * @param list<array{0: Connector, 1: string}> $aliases
     */
    private function createAccount(
        ObjectManager $manager,
        string $name,
        string $email,
        array $aliases,
    ): Account {
        $account = new Account();
        $account->setName($name);
        $account->setEmail($email);
        $manager->persist($account);

        foreach ($aliases as [$connector, $localPart]) {
            $alias = new AccountAlias();
            $alias->setAccount($account);
            $alias->setConnector($connector);
            $alias->setLocalPart($localPart);
            $manager->persist($alias);
            $account->addAlias($alias);
            $account->addAddress($this->findOrCreateAddress($manager, $alias->getAddress(), $name));
        }

        $manager->flush();

        return $account;
    }

    private function createConnector(
        ObjectManager $manager,
        string $name,
        string $domain,
        string $hostPrefix,
        ?Account $defaultAccount,
    ): Connector {
        $connector = new Connector();
        $connector->setName($name);
        $connector->setDomain($domain);
        $connector->setDefaultAccount($defaultAccount);
        $manager->persist($connector);

        $config = new ConnectorConfig();
        $config->setConnector($connector);
        $config->setPluginKey('pop3');
        $config->setConfig([
            'pop3Host' => sprintf('pop3.%s.example', $hostPrefix),
            'pop3Port' => 995,
            'pop3Username' => $domain,
            'pop3Password' => 'secret',
            'pop3Security' => 'ssl',
            'pop3DeleteAfterFetch' => false,
            'smtpHost' => sprintf('smtp.%s.example', $hostPrefix),
            'smtpPort' => 465,
            'smtpUsername' => $domain,
            'smtpPassword' => 'secret',
            'smtpSecurity' => 'ssl',
        ]);
        $manager->persist($config);
        $manager->flush();

        return $connector;
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
    private function accountAddresses(ObjectManager $manager, Account $account): array
    {
        $addresses = [$this->findOrCreateAddress($manager, $account->getEmail(), $account->getName())];
        foreach ($account->getAliases() as $alias) {
            $addresses[] = $this->findOrCreateAddress($manager, $alias->getAddress(), $account->getName());
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
    private function seedCalendarEvents(ObjectManager $manager, Account $account): void
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
                ->setAccount($account);
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

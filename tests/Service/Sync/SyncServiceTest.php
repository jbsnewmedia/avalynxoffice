<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Sync;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Body;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailAddressLink;
use AvalynxOffice\Entity\EmailBodyLink;
use AvalynxOffice\Entity\Enum\BodyContentType;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Entity\SyncRun;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Repository\SyncRunRepository;
use AvalynxOffice\Service\ConnectorPluginRegistry;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailSendService;
use AvalynxOffice\Service\Security\SecretCrypto;
use AvalynxOffice\Service\Sync\SyncService;
use AvalynxOffice\Tests\Support\AllowAllLicenseEnforcement;
use AvalynxOffice\Tests\Support\FakeConnectorPlugin;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\NullTransport;

#[Group('integration')]
final class SyncServiceTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $em;

    private SyncRunRepository $syncRunRepository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->syncRunRepository = self::getContainer()->get(SyncRunRepository::class);
        $this->refreshDatabase($this->em);
    }

    /**
     * Builds a sync service whose plugin registry only contains the given
     * fake plugin.
     *
     * @param list<FakeConnectorPlugin> $plugins
     */
    private function createService(array $plugins): SyncService
    {
        $registry = $this->createRegistry($plugins);
        $mailSendService = new MailSendService(
            self::getContainer()->get(EmailRepository::class),
            $this->em,
            self::getContainer()->get(FolderManager::class),
            self::getContainer()->get(AttachmentStorage::class),
            $registry,
            $this->em->getRepository(Connector::class),
            new AllowAllLicenseEnforcement(),
        );

        return new SyncService($registry, $this->em->getRepository(Connector::class), $this->syncRunRepository, $mailSendService);
    }

    /**
     * @param list<FakeConnectorPlugin> $plugins
     */
    private function createRegistry(array $plugins): ConnectorPluginRegistry
    {
        $configRepository = self::getContainer()->get(ConnectorConfigRepository::class);
        \assert($configRepository instanceof ConnectorConfigRepository);
        $secretCrypto = self::getContainer()->get(SecretCrypto::class);
        \assert($secretCrypto instanceof SecretCrypto);

        return new ConnectorPluginRegistry($plugins, $configRepository, $secretCrypto);
    }

    private function createConnector(string $name): Connector
    {
        $connector = (new Connector())
            ->setName($name)
            ->setDomain('example.com');

        $this->em->persist($connector);
        $this->em->flush();

        return $connector;
    }

    public function testRunWithoutConnectorsRecordsSuccessfulEmptyPass(): void
    {
        $service = $this->createService([]);
        $run = $service->run(['mails' => 100], \AvalynxOffice\Entity\SyncRun::TRIGGER_DAEMON);

        self::assertSame(\AvalynxOffice\Entity\SyncRun::STATUS_SUCCESS, $run->getStatus());
        self::assertSame(\AvalynxOffice\Entity\SyncRun::TRIGGER_DAEMON, $run->getTrigger());
        self::assertSame(0, $run->getMailsImported());
        self::assertSame(0, $run->getMailsSkipped());
        self::assertSame(0, $run->getMailsErrors());
        self::assertNotNull($run->getId());
        self::assertNotNull($run->getFinishedAt());
        self::assertNull($run->getMessage());
        self::assertSame($run, $this->syncRunRepository->findLatest());
    }

    public function testRunFetchesOldestFirstFromEveryConfiguredConnector(): void
    {
        $fake = new FakeConnectorPlugin(inputConfigured: true);
        $fake->fetchResult = ['imported' => 2, 'skipped' => 1, 'errors' => 0];
        $fake->contactFetchResult = ['imported' => 5, 'skipped' => 0, 'errors' => 0];
        $fake->calendarFetchResult = ['imported' => 7, 'skipped' => 0, 'errors' => 0];
        $this->createConnector('A Connector');
        $this->createConnector('B Connector');

        $service = $this->createService([$fake]);
        $run = $service->run([
            'mails' => 25,
            'contacts' => 40,
            'calendar' => 60,
            'oldestFirst' => true,
        ], \AvalynxOffice\Entity\SyncRun::TRIGGER_MANUAL);

        self::assertSame(\AvalynxOffice\Entity\SyncRun::STATUS_SUCCESS, $run->getStatus());
        self::assertSame(\AvalynxOffice\Entity\SyncRun::TRIGGER_MANUAL, $run->getTrigger());
        self::assertSame(4, $run->getMailsImported());
        self::assertSame(2, $run->getMailsSkipped());
        self::assertSame(0, $run->getMailsErrors());
        self::assertSame(10, $run->getContactsCount());
        self::assertSame(14, $run->getCalendarCount());
        self::assertCount(2, $fake->fetchCalls);
        self::assertCount(2, $fake->contactFetchCalls);
        self::assertCount(2, $fake->calendarFetchCalls);

        $first = $fake->fetchCalls[0];
        self::assertSame('A Connector', $first[0]->getName());
        self::assertSame(25, $first[2]->limit);
        self::assertTrue($first[2]->oldestFirst);
        self::assertSame(40, $fake->contactFetchCalls[0][2]->limit);
        self::assertSame(60, $fake->calendarFetchCalls[0][2]->limit);
    }

    public function testRunWithZeroLimitDoesNotFetch(): void
    {
        $fake = new FakeConnectorPlugin(inputConfigured: true);
        $this->createConnector('Zero Connector');

        $service = $this->createService([$fake]);
        $run = $service->run(['mails' => 0, 'contacts' => 0, 'calendar' => 0]);

        self::assertSame(\AvalynxOffice\Entity\SyncRun::STATUS_SUCCESS, $run->getStatus());
        self::assertSame(0, $run->getMailsImported());
        self::assertSame(0, $run->getContactsCount());
        self::assertSame(0, $run->getCalendarCount());
        self::assertSame([], $fake->fetchCalls);
        self::assertSame([], $fake->contactFetchCalls);
        self::assertSame([], $fake->calendarFetchCalls);
    }

    public function testRunSkipsPluginsWithoutConfiguredInput(): void
    {
        $unconfigured = new FakeConnectorPlugin(key: 'fake-b', inputConfigured: false);
        $this->createConnector('Unconfigured Connector');

        $service = $this->createService([$unconfigured]);
        $run = $service->run(['mails' => 10, 'contacts' => 5, 'calendar' => 5]);

        // A plugin without a configured input is never asked to fetch: the
        // pass finishes successfully with zero counters.
        self::assertSame(\AvalynxOffice\Entity\SyncRun::STATUS_SUCCESS, $run->getStatus());
        self::assertSame(0, $run->getMailsImported());
        self::assertSame(0, $run->getContactsCount());
        self::assertSame(0, $run->getCalendarCount());
        self::assertSame([], $unconfigured->fetchCalls);
        self::assertSame([], $unconfigured->contactFetchCalls);
        self::assertSame([], $unconfigured->calendarFetchCalls);
    }

    public function testRunRecordsErrorWhenFetcherThrows(): void
    {
        $fake = new FakeConnectorPlugin(inputConfigured: true);
        $this->createConnector('Crash Connector');

        $registry = new class([$fake], self::getContainer()->get(ConnectorConfigRepository::class), self::getContainer()->get(SecretCrypto::class)) extends ConnectorPluginRegistry {
            #[\Override]
            public function getFetchers(Connector $connector): array
            {
                throw new \RuntimeException('POP3 host unreachable.');
            }
        };

        $service = new SyncService($registry, $this->em->getRepository(Connector::class), $this->syncRunRepository, self::getContainer()->get(MailSendService::class));
        $run = $service->run(['mails' => 10]);

        self::assertSame(\AvalynxOffice\Entity\SyncRun::STATUS_ERROR, $run->getStatus());
        self::assertSame('POP3 host unreachable.', $run->getMessage());
        self::assertNotNull($run->getFinishedAt());
    }

    public function testRunSyncsContactsAndCalendarFromEveryConfiguredConnector(): void
    {
        $fake = new FakeConnectorPlugin(inputConfigured: true);
        $fake->contactFetchResult = ['imported' => 2, 'skipped' => 1, 'errors' => 0];
        $fake->calendarFetchResult = ['imported' => 3, 'skipped' => 0, 'errors' => 1];
        $this->createConnector('Sync Connector');

        $service = $this->createService([$fake]);
        $run = $service->run(['mails' => 0, 'contacts' => 5, 'calendar' => 6]);

        self::assertSame(\AvalynxOffice\Entity\SyncRun::STATUS_SUCCESS, $run->getStatus());
        self::assertSame(2, $run->getContactsCount());
        self::assertSame(3, $run->getCalendarCount());
        self::assertCount(1, $fake->contactFetchCalls);
        self::assertCount(1, $fake->calendarFetchCalls);
        self::assertSame(5, $fake->contactFetchCalls[0][2]->limit);
        self::assertSame(6, $fake->calendarFetchCalls[0][2]->limit);
    }

    public function testRunWithZeroContactAndCalendarLimitsDoesNotFetch(): void
    {
        $fake = new FakeConnectorPlugin(inputConfigured: true);
        $this->createConnector('Idle Connector');

        $service = $this->createService([$fake]);
        $run = $service->run(['mails' => 0, 'contacts' => 0, 'calendar' => 0]);

        self::assertSame(\AvalynxOffice\Entity\SyncRun::STATUS_SUCCESS, $run->getStatus());
        self::assertSame(0, $run->getContactsCount());
        self::assertSame(0, $run->getCalendarCount());
        self::assertSame([], $fake->contactFetchCalls);
        self::assertSame([], $fake->calendarFetchCalls);
    }

    public function testRunRecordsErrorWhenContactFetcherThrows(): void
    {
        $fake = new FakeConnectorPlugin(inputConfigured: true);
        $this->createConnector('Crash Contacts Connector');

        $registry = new class([$fake], self::getContainer()->get(ConnectorConfigRepository::class), self::getContainer()->get(SecretCrypto::class)) extends ConnectorPluginRegistry {
            #[\Override]
            public function getContactFetchers(Connector $connector): array
            {
                throw new \RuntimeException('EWS contacts unreachable.');
            }
        };

        $service = new SyncService($registry, $this->em->getRepository(Connector::class), $this->syncRunRepository, self::getContainer()->get(MailSendService::class));
        $run = $service->run(['mails' => 0, 'contacts' => 10]);

        self::assertSame(\AvalynxOffice\Entity\SyncRun::STATUS_ERROR, $run->getStatus());
        self::assertSame('EWS contacts unreachable.', $run->getMessage());
    }

    public function testRunRecordsErrorWhenCalendarFetcherThrows(): void
    {
        $fake = new FakeConnectorPlugin(inputConfigured: true);
        $this->createConnector('Crash Calendar Connector');

        $registry = new class([$fake], self::getContainer()->get(ConnectorConfigRepository::class), self::getContainer()->get(SecretCrypto::class)) extends ConnectorPluginRegistry {
            #[\Override]
            public function getCalendarFetchers(Connector $connector): array
            {
                throw new \RuntimeException('EWS calendar unreachable.');
            }
        };

        $service = new SyncService($registry, $this->em->getRepository(Connector::class), $this->syncRunRepository, self::getContainer()->get(MailSendService::class));
        $run = $service->run(['mails' => 0, 'calendar' => 10]);

        self::assertSame(\AvalynxOffice\Entity\SyncRun::STATUS_ERROR, $run->getStatus());
        self::assertSame('EWS calendar unreachable.', $run->getMessage());
    }

    public function testRunSendsPendingOutgoingMails(): void
    {
        $connector = $this->createConnector('SMTP Connector');
        $account = (new Account())
            ->setName('Account')
            ->setEmail('sender@example.com');
        $this->em->persist($account);

        $email = (new Email())
            ->setAccount($account)
            ->setSubject('Geplant im Sync')
            ->setIsOutgoing(true)
            ->setIsDraft(false);
        $senderAddress = (new Address())->setEmail('sender@example.com');
        $senderLink = (new EmailAddressLink())
            ->setType(EmailAddressType::SENDER)
            ->setAddress($senderAddress);
        $email->addEmailAddress($senderLink);
        $recipientAddress = (new Address())->setEmail('recipient@example.com');
        $recipientLink = (new EmailAddressLink())
            ->setType(EmailAddressType::RECIPIENT)
            ->setAddress($recipientAddress);
        $email->addEmailAddress($recipientLink);
        $body = (new Body())->setContent('Plain body');
        $bodyLink = (new EmailBodyLink())
            ->setContentType(BodyContentType::TEXT)
            ->setBody($body);
        $email->addEmailBody($bodyLink);
        $this->em->persist($email);
        $this->em->persist($senderAddress);
        $this->em->persist($senderLink);
        $this->em->persist($recipientAddress);
        $this->em->persist($recipientLink);
        $this->em->persist($body);
        $this->em->persist($bodyLink);
        $this->em->flush();

        $plugin = new FakeConnectorPlugin(outputConfigured: true, mailer: new Mailer(new NullTransport()));
        $service = $this->createService([$plugin]);

        $run = $service->run(['mails' => 0, 'contacts' => 0, 'calendar' => 0]);

        self::assertSame(SyncRun::STATUS_SUCCESS, $run->getStatus());
        $this->em->refresh($email);
        self::assertTrue($email->isSent(), 'The sync pass must send pending outgoing mails.');
        self::assertFalse($email->isSending());
        self::assertSame([$connector], $plugin->mailerCalls);
    }

    public function testRunStaysSuccessfulWhenMailSendingFails(): void
    {
        $this->createConnector('Broken SMTP Connector');
        $account = (new Account())
            ->setName('Account')
            ->setEmail('sender@example.com');
        $this->em->persist($account);

        $email = (new Email())
            ->setAccount($account)
            ->setSubject('Verwaiste Mail')
            ->setIsOutgoing(true)
            ->setIsDraft(false);
        $senderAddress = (new Address())->setEmail('sender@example.com');
        $senderLink = (new EmailAddressLink())
            ->setType(EmailAddressType::SENDER)
            ->setAddress($senderAddress);
        $email->addEmailAddress($senderLink);
        $body = (new Body())->setContent('Plain body');
        $bodyLink = (new EmailBodyLink())
            ->setContentType(BodyContentType::TEXT)
            ->setBody($body);
        $email->addEmailBody($bodyLink);
        $this->em->persist($email);
        $this->em->persist($senderAddress);
        $this->em->persist($senderLink);
        $this->em->persist($body);
        $this->em->persist($bodyLink);
        $this->em->flush();

        $failingMailer = $this->createStub(MailerInterface::class);
        $failingMailer->method('send')->willThrowException(new \RuntimeException('SMTP unavailable'));
        $service = $this->createService([new FakeConnectorPlugin(outputConfigured: true, mailer: $failingMailer)]);

        $run = $service->run(['mails' => 0, 'contacts' => 0, 'calendar' => 0]);

        self::assertSame(SyncRun::STATUS_SUCCESS, $run->getStatus(), 'A failing send pass must not turn the sync run red.');
        $this->em->refresh($email);
        self::assertFalse($email->isSent());
        self::assertFalse($email->isSending(), 'The sending flag must be reset so the next pass retries the mail.');
    }
}

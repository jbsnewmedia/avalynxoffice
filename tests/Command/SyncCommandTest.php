<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Command;

use AvalynxOffice\Command\SyncDaemonCommand;
use AvalynxOffice\Command\SyncRunCommand;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\SyncRun;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use AvalynxOffice\Repository\SyncRunRepository;
use AvalynxOffice\Service\ConnectorPluginRegistry;
use AvalynxOffice\Service\Sync\SyncService;
use AvalynxOffice\Service\Sync\SyncSettingsService;
use AvalynxOffice\Tests\Support\FakeConnectorPlugin;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Executes the sync commands against a real sync service with a fake
 * connector plugin.
 */
#[Group('integration')]
final class SyncCommandTest extends KernelTestCase
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

    private function syncSettings(): SyncSettingsService
    {
        $settings = self::getContainer()->get(SyncSettingsService::class);
        \assert($settings instanceof SyncSettingsService);

        return $settings;
    }

    private function createCommand(): SyncRunCommand|SyncDaemonCommand
    {
        $fake = new FakeConnectorPlugin(inputConfigured: true);
        $fake->fetchResult = ['imported' => 3, 'skipped' => 1, 'errors' => 0];
        $fake->contactFetchResult = ['imported' => 3, 'skipped' => 0, 'errors' => 0];
        $fake->calendarFetchResult = ['imported' => 3, 'skipped' => 0, 'errors' => 0];
        $configRepository = self::getContainer()->get(ConnectorConfigRepository::class);
        \assert($configRepository instanceof ConnectorConfigRepository);
        $registry = new ConnectorPluginRegistry([$fake], $configRepository);

        $connector = (new Connector())
            ->setName('Test Connector')
            ->setDomain('example.com');
        $this->em->persist($connector);
        $this->em->flush();

        return new SyncRunCommand(new SyncService($registry, $this->em->getRepository(Connector::class), $this->syncRunRepository), $this->syncSettings());
    }

    private function createDaemonCommand(): SyncDaemonCommand
    {
        $fake = new FakeConnectorPlugin(inputConfigured: true);
        $fake->fetchResult = ['imported' => 2, 'skipped' => 0, 'errors' => 0];
        $configRepository = self::getContainer()->get(ConnectorConfigRepository::class);
        \assert($configRepository instanceof ConnectorConfigRepository);
        $registry = new ConnectorPluginRegistry([$fake], $configRepository);

        $connector = (new Connector())
            ->setName('Test Connector')
            ->setDomain('example.com');
        $this->em->persist($connector);
        $this->em->flush();

        return new SyncDaemonCommand(new SyncService($registry, $this->em->getRepository(Connector::class), $this->syncRunRepository), $this->syncSettings());
    }

    public function testRunCommandOutputsCountsAndSucceeds(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->execute(['--mails' => '42', '--contacts' => '20', '--calendar' => '30']);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Sync abgeschlossen.', $display);
        self::assertStringContainsString('3 importiert, 1 übersprungen, 0 Fehler.', $display);
        self::assertStringContainsString('Kontakte: 3, Termine: 3.', $display);

        $run = $this->syncRunRepository->findLatest();
        self::assertNotNull($run);
        self::assertSame(SyncRun::STATUS_SUCCESS, $run->getStatus());
        self::assertSame(SyncRun::TRIGGER_MANUAL, $run->getTrigger());
        self::assertSame(3, $run->getMailsImported());
        self::assertSame(3, $run->getContactsCount());
        self::assertSame(3, $run->getCalendarCount());
    }

    public function testDaemonRunsConfiguredNumberOfPasses(): void
    {
        $this->syncSettings()->save(['intervalSeconds' => 0, 'busyIntervalSeconds' => 0]);
        $tester = new CommandTester($this->createDaemonCommand());
        $tester->execute(['--max-runs' => '2']);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Sync-Dienst gestartet (Intervall: 0s, Backfill-Intervall: 0s, Mails: 100, Kontakte: 100, Termine: 100 pro Lauf).', $display);
        self::assertStringContainsString('Durchlauf #1: success', $display);
        self::assertStringContainsString('Durchlauf #2: success', $display);
        self::assertStringContainsString('Sync-Dienst beendet.', $display);

        $runs = $this->syncRunRepository->findAll();
        self::assertCount(2, $runs);
        foreach ($runs as $run) {
            self::assertSame(SyncRun::STATUS_SUCCESS, $run->getStatus());
            self::assertSame(SyncRun::TRIGGER_DAEMON, $run->getTrigger());
            self::assertSame(2, $run->getMailsImported());
        }
    }

    public function testDaemonContinuesImmediatelyWhileEntriesFlow(): void
    {
        $fake = new FakeConnectorPlugin(inputConfigured: true);
        $fake->fetchResult = ['imported' => 2, 'skipped' => 0, 'errors' => 0];
        $configRepository = self::getContainer()->get(ConnectorConfigRepository::class);
        \assert($configRepository instanceof ConnectorConfigRepository);
        $registry = new ConnectorPluginRegistry([$fake], $configRepository);

        $connector = (new Connector())
            ->setName('Backfill Connector')
            ->setDomain('backfill.example.com');
        $this->em->persist($connector);
        $this->em->flush();

        $command = new SyncDaemonCommand(new SyncService($registry, $this->em->getRepository(Connector::class), $this->syncRunRepository), $this->syncSettings());
        $tester = new CommandTester($command);
        // While entries are imported, the long idle pause (60s) must NOT be
        // taken: both passes run immediately (busy pause = 0s).
        $this->syncSettings()->save(['intervalSeconds' => 60, 'busyIntervalSeconds' => 0]);
        $tester->execute(['--max-runs' => '2']);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Durchlauf #1: success', $display);
        self::assertStringContainsString('Durchlauf #2: success', $display);
        self::assertStringContainsString('Pause: 0s', $display);
    }

    public function testDaemonUsesIdleIntervalWhenNothingIsImported(): void
    {
        $fake = new FakeConnectorPlugin(inputConfigured: true);
        $configRepository = self::getContainer()->get(ConnectorConfigRepository::class);
        \assert($configRepository instanceof ConnectorConfigRepository);
        $registry = new ConnectorPluginRegistry([$fake], $configRepository);

        $command = new SyncDaemonCommand(new SyncService($registry, $this->em->getRepository(Connector::class), $this->syncRunRepository), $this->syncSettings());
        $tester = new CommandTester($command);
        // Nothing imported: the long idle pause is taken between passes.
        $this->syncSettings()->save(['intervalSeconds' => 1, 'busyIntervalSeconds' => 0]);
        $tester->execute(['--max-runs' => '2']);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Durchlauf #1: success', $display);
        self::assertStringContainsString('Durchlauf #2: success', $display);
        self::assertStringContainsString('Pause: 1s', $display);
    }

    public function testDaemonUsesConfiguredSettingsFromAdmin(): void
    {
        // The admin system status page stores the settings: the daemon
        // re-reads them on every pass without a restart.
        $settingsService = self::getContainer()->get(\AvalynxOffice\Service\Sync\SyncSettingsService::class);
        \assert($settingsService instanceof \AvalynxOffice\Service\Sync\SyncSettingsService);
        $settingsService->save([
            'intervalSeconds' => 0,
            'busyIntervalSeconds' => 0,
            'mailsLimit' => 5,
            'contactsLimit' => 6,
            'calendarLimit' => 7,
        ]);

        $tester = new CommandTester($this->createDaemonCommand());
        $tester->execute(['--max-runs' => '1']);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('(Intervall: 0s, Backfill-Intervall: 0s, Mails: 5, Kontakte: 6, Termine: 7 pro Lauf).', $display);
        self::assertStringContainsString('Mails: 2 importiert', $display);

        $runs = $this->syncRunRepository->findAll();
        self::assertCount(1, $runs);
        self::assertSame(2, $runs[0]->getMailsImported());
    }

    public function testRunCommandReportsFailureOnErrorStatus(): void
    {
        $fake = new FakeConnectorPlugin(inputConfigured: true);
        $configRepository = self::getContainer()->get(ConnectorConfigRepository::class);
        \assert($configRepository instanceof ConnectorConfigRepository);
        $registry = new class([$fake], $configRepository) extends ConnectorPluginRegistry {
            #[\Override]
            public function getFetchers(Connector $connector): array
            {
                throw new \RuntimeException('POP3 host unreachable.');
            }
        };

        $connector = (new Connector())
            ->setName('Crash Connector')
            ->setDomain('example.com');
        $this->em->persist($connector);
        $this->em->flush();

        $command = new SyncRunCommand(new SyncService($registry, $this->em->getRepository(Connector::class), $this->syncRunRepository), $this->syncSettings());
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Sync fehlgeschlagen: POP3 host unreachable.', $tester->getDisplay());

        $run = $this->syncRunRepository->findLatest();
        self::assertNotNull($run);
        self::assertSame(SyncRun::STATUS_ERROR, $run->getStatus());
        self::assertSame('POP3 host unreachable.', $run->getMessage());
    }
}

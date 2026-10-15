<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CompileScssCommand;
use App\Command\ListCssClassesCommand;
use App\Command\MailboxAddCommand;
use App\Command\MailboxConfigureSmtpCommand;
use App\Command\MailClearCommand;
use App\Command\MailFetchCommand;
use App\Command\MailSendCommand;
use App\Command\PurgeCssCommand;
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
use App\Entity\MailboxConnector;
use App\Entity\User;
use App\Repository\EmailRepository;
use App\Repository\MailboxConnectorRepository;
use App\Repository\MailboxRepository;
use App\Repository\UserRepository;
use App\Service\Mail\AttachmentStorage;
use App\Service\Mail\FolderManager;
use App\Service\Mail\Pop3Fetcher;
use App\Service\Mail\SmtpMailerFactory;
use App\Tests\Support\AllowAllLicenseEnforcement;
use App\Util\CssClassCollector;
use App\Util\CssPurgerFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use JBSNewMedia\CssPurger\CssPurger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\NullTransport;

final class CommandGapFillTest extends TestCase
{
    public function testListCssClassesCommandSupportsGroupedJsonAndPlainOutput(): void
    {
        $collector = $this->createMock(CssClassCollector::class);
        $collector->expects(self::exactly(3))
            ->method('collectAll')
            ->willReturn(['btn', 'alert']);
        $collector->expects(self::once())
            ->method('collectGrouped')
            ->willReturn(['mail/index.html.twig' => ['btn', 'alert']]);

        $command = new ListCssClassesCommand($collector);

        $grouped = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $grouped->execute(['--grouped' => true]));
        self::assertStringContainsString('# mail/index.html.twig', $grouped->getDisplay());
        self::assertStringContainsString('Gesamt eindeutige Klassen: 2', $grouped->getDisplay());

        $json = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $json->execute(['--json' => true]));
        self::assertStringContainsString('"total": 2', $json->getDisplay());

        $plain = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $plain->execute([]));
        self::assertStringContainsString("btn\nalert", trim($plain->getDisplay()));
    }

    public function testCompileScssCommandHandlesMissingAndValidFiles(): void
    {
        $command = new CompileScssCommand();

        $missing = new CommandTester($command);
        self::assertSame(Command::FAILURE, $missing->execute([
            'input' => 'var/test/scss/missing.scss',
            'output' => 'var/test/scss/out.css',
        ]));
        self::assertStringContainsString('Input file not found', $missing->getDisplay());

        $baseDir = self::varDir();
        $suffix = bin2hex(random_bytes(4));
        self::assertNotFalse(file_put_contents($baseDir.'/compile-'.$suffix.'.scss', '$color: #123456; .box { color: $color; }'));

        $success = new CommandTester(new CompileScssCommand());
        self::assertSame(Command::SUCCESS, $success->execute([
            'input' => $baseDir.'/compile-'.$suffix.'.scss',
            'output' => $baseDir.'/compile-'.$suffix.'.css',
            '--source-map' => true,
        ]));

        self::assertFileExists($baseDir.'/compile-'.$suffix.'.css');
        $compiledCss = file_get_contents($baseDir.'/compile-'.$suffix.'.css') ?: '';
        self::assertStringContainsString('.box', $compiledCss);

        unlink($baseDir.'/compile-'.$suffix.'.scss');
        unlink($baseDir.'/compile-'.$suffix.'.css');
        if (is_file($baseDir.'/compile-'.$suffix.'.css.map')) {
            unlink($baseDir.'/compile-'.$suffix.'.css.map');
        }
    }

    public function testCompileScssCommandReportsCompilationErrors(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $inputPath = self::varDir().'/compile-broken-'.$suffix.'.scss';

        self::assertNotFalse(file_put_contents($inputPath, '.broken { color: #123;'));

        try {
            $tester = new CommandTester(new CompileScssCommand());
            self::assertSame(Command::FAILURE, $tester->execute([
                'input' => $inputPath,
                'output' => self::varDir().'/compile-broken-'.$suffix.'.css',
            ]));
            self::assertStringContainsString('SCSS compilation failed', $tester->getDisplay());
        } finally {
            @unlink($inputPath);
            @unlink(self::varDir().'/compile-broken-'.$suffix.'.css');
        }
    }

    public function testMailFetchCommandHandlesMissingMailboxesAndEmptyRepositories(): void
    {
        $mailboxRepository = $this->createMock(MailboxRepository::class);
        $mailboxRepository->expects(self::once())
            ->method('find')
            ->with('missing@example.com')
            ->willReturn(null);
        $mailboxRepository->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'missing@example.com'])
            ->willReturn(null);

        $fetcher = $this->createMock(Pop3Fetcher::class);
        $fetcher->expects(self::never())->method('fetchForConnector');

        $missing = new CommandTester(new MailFetchCommand($mailboxRepository, $fetcher));
        self::assertSame(Command::FAILURE, $missing->execute(['--mailbox' => 'missing@example.com']));
        self::assertStringContainsString('Mailbox nicht gefunden', $missing->getDisplay());

        $emptyRepository = $this->createMock(MailboxRepository::class);
        $emptyRepository->expects(self::once())
            ->method('findAll')
            ->willReturn([]);

        $empty = new CommandTester(new MailFetchCommand($emptyRepository, $fetcher));
        self::assertSame(Command::SUCCESS, $empty->execute([]));
        self::assertStringContainsString('Keine Mailboxen vorhanden.', $empty->getDisplay());
    }

    public function testMailFetchCommandCreatesErrorLogDirectoryAndInformsOutput(): void
    {
        $mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');
        $connector = (new MailboxConnector())
            ->setName('Primary')
            ->setEmail('connector@example.com');
        $mailbox->addConnector($connector);

        $mailboxRepository = $this->createMock(MailboxRepository::class);
        $mailboxRepository->expects(self::once())
            ->method('findAll')
            ->willReturn([$mailbox]);

        $fetcher = $this->createMock(Pop3Fetcher::class);
        $fetcher->expects(self::once())->method('setOutput')->willReturnSelf();
        $fetcher->expects(self::once())->method('setErrorLogPath')->willReturnSelf();
        $fetcher->method('fetchForConnector')->willReturn(['imported' => 0, 'skipped' => 0, 'errors' => 0]);

        $logPath = sys_get_temp_dir().'/mail-fetch-log-'.bin2hex(random_bytes(4)).'/nested/fetch-errors.log';
        $tester = new CommandTester(new MailFetchCommand($mailboxRepository, $fetcher));
        self::assertSame(Command::SUCCESS, $tester->execute(['--error-log' => $logPath]));

        self::assertStringContainsString('Fehler-Log: '.$logPath, $tester->getDisplay());
        self::assertDirectoryExists(dirname($logPath));
        @rmdir(dirname($logPath));
    }

    public function testMailFetchCommandFallsBackToEmailLookupWhenIdLookupFails(): void
    {
        $mailboxRepository = $this->createMock(MailboxRepository::class);
        $mailboxRepository->expects(self::once())
            ->method('find')
            ->with('fallback@example.com')
            ->willThrowException(new \RuntimeException('db kaputt'));
        $mailboxRepository->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'fallback@example.com'])
            ->willReturn(null);

        $fetcher = $this->createMock(Pop3Fetcher::class);
        $fetcher->expects(self::never())->method('fetchForConnector');

        $tester = new CommandTester(new MailFetchCommand($mailboxRepository, $fetcher));
        self::assertSame(Command::FAILURE, $tester->execute(['--mailbox' => 'fallback@example.com']));
        self::assertStringContainsString('Mailbox nicht gefunden', $tester->getDisplay());
    }

    public function testMailFetchCommandProcessesConnectorsAndReportsTotals(): void
    {
        $mailboxWithoutConnectors = (new Mailbox())
            ->setName('Empty')
            ->setEmail('empty@example.com');
        $mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');
        $connector = (new MailboxConnector())
            ->setName('Primary')
            ->setEmail('connector@example.com');
        $mailbox->addConnector($connector);

        $mailboxRepository = $this->createMock(MailboxRepository::class);
        $mailboxRepository->expects(self::once())
            ->method('findAll')
            ->willReturn([$mailboxWithoutConnectors, $mailbox]);

        $fetcher = $this->createMock(Pop3Fetcher::class);
        $fetcher->expects(self::once())->method('setOutput');
        $fetcher->expects(self::once())
            ->method('fetchForConnector')
            ->with($connector, 2, true, true)
            ->willReturn(['imported' => 3, 'skipped' => 1, 'errors' => 1]);

        $tester = new CommandTester(new MailFetchCommand($mailboxRepository, $fetcher));
        self::assertSame(Command::FAILURE, $tester->execute([
            '--limit' => '2',
            '--oldest' => true,
            '--delete-after' => true,
        ]));

        self::assertStringContainsString('Kein Connector konfiguriert.', $tester->getDisplay());
        self::assertStringContainsString('Abruf startet', $tester->getDisplay());
        self::assertStringContainsString('Gesamt: importiert=3, übersprungen=1, Fehler=1', $tester->getDisplay());
    }

    public function testMailFetchCommandReportsWhenNoConnectorsCanBeProcessed(): void
    {
        $mailbox = (new Mailbox())
            ->setName('Empty')
            ->setEmail('empty@example.com');

        $mailboxRepository = $this->createMock(MailboxRepository::class);
        $mailboxRepository->expects(self::once())
            ->method('findAll')
            ->willReturn([$mailbox]);

        $fetcher = $this->createMock(Pop3Fetcher::class);
        $fetcher->expects(self::never())->method('fetchForConnector');

        $tester = new CommandTester(new MailFetchCommand($mailboxRepository, $fetcher));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Keine Connectoren vorhanden.', $tester->getDisplay());
    }

    public function testMailboxAddCommandCreatesMailboxConnectorAndAssignsAdminUserWhenAvailable(): void
    {
        $admin = (new User())
            ->setEmail('admin@example.com')
            ->setPassword('secret');

        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'admin@example.com'])
            ->willReturn($admin);

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::once())->method('flush');

        $tester = new CommandTester(new MailboxAddCommand($entityManager));
        self::assertSame(Command::SUCCESS, $tester->execute([
            'email' => 'mailbox@example.com',
            'password' => 'secret',
            '--name' => 'Mailbox',
            '--connector-email' => 'connector@example.com',
            '--connector-name' => 'Connector',
            '--host' => 'pop.example.com',
            '--port' => '995',
            '--user' => 'pop-user',
            '--security' => 'ssl',
            '--smtp-host' => 'smtp.example.com',
            '--smtp-port' => '587',
            '--smtp-user' => 'smtp-user',
            '--smtp-password' => 'smtp-secret',
            '--smtp-security' => 'tls',
        ]));

        self::assertCount(2, $persisted);
        self::assertInstanceOf(Mailbox::class, $persisted[0]);
        self::assertInstanceOf(MailboxConnector::class, $persisted[1]);
        self::assertSame('mailbox@example.com', $persisted[0]->getEmail());
        self::assertSame('smtp.example.com', $persisted[1]->getSmtpHost());
        self::assertCount(1, $admin->getMailboxes());
        self::assertStringContainsString('Mailbox wird dem User admin@example.com zugeordnet.', $tester->getDisplay());
        self::assertStringContainsString('wurde erfolgreich angelegt', $tester->getDisplay());
    }

    public function testMailboxConfigureSmtpCommandHandlesConnectorLookupMailboxFallbackAndMissingIdentifier(): void
    {
        $connector = (new MailboxConnector())
            ->setEmail('connector@example.com');
        $mailbox = (new Mailbox())
            ->setName('Mailbox')
            ->setEmail('mailbox@example.com');
        $mailbox->addConnector((new MailboxConnector())->setEmail('primary@example.com')->setIsPrimary(true));

        $connectorRepository = $this->createStub(MailboxConnectorRepository::class);
        $connectorRepository->method('findOneBy')
            ->willReturnCallback(static fn (array $criteria): ?MailboxConnector => match ($criteria['email'] ?? null) {
                'connector@example.com' => $connector,
                'mailbox@example.com' => null,
                'missing@example.com' => null,
                default => null,
            });
        $connectorRepository->method('find')
            ->willReturnCallback(static fn (string $id): ?MailboxConnector => 'missing@example.com' === $id ? null : null);

        $mailboxRepository = $this->createStub(MailboxRepository::class);
        $mailboxRepository->method('findOneBy')
            ->willReturnCallback(static fn (array $criteria): ?Mailbox => 'mailbox@example.com' === ($criteria['email'] ?? null) ? $mailbox : null);
        $mailboxRepository->method('find')
            ->willReturnCallback(static fn (string $id): ?Mailbox => 'missing@example.com' === $id ? null : null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('flush');

        $command = new MailboxConfigureSmtpCommand($mailboxRepository, $connectorRepository, $entityManager);

        $direct = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $direct->execute([
            'identifier' => 'connector@example.com',
            '--host' => 'smtp.example.com',
            '--port' => '2525',
            '--user' => 'smtp-user',
            '--password' => 'smtp-pass',
            '--security' => 'tls',
        ]));
        self::assertSame('smtp.example.com', $connector->getSmtpHost());
        self::assertSame(2525, $connector->getSmtpPort());
        self::assertSame('smtp-user', $connector->getSmtpUsername());
        self::assertSame('smtp-pass', $connector->getSmtpPassword());
        self::assertSame('tls', $connector->getSmtpSecurity());

        $fallback = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $fallback->execute([
            'identifier' => 'mailbox@example.com',
            '--host' => 'fallback.example.com',
        ]));
        self::assertSame('fallback.example.com', $mailbox->getPrimaryConnector()?->getSmtpHost());

        $missing = new CommandTester($command);
        self::assertSame(Command::FAILURE, $missing->execute([
            'identifier' => 'missing@example.com',
        ]));
        self::assertStringContainsString('missing@example.com', $missing->getDisplay());
    }

    public function testMailClearCommandAbortsWhenConfirmationIsDenied(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getConnection');

        $tester = new CommandTester(new MailClearCommand($entityManager));
        $tester->setInputs(['no']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Vorgang abgebrochen.', $tester->getDisplay());
    }

    public function testMailClearCommandClearsSqliteTablesAndFallsBackToDelete(): void
    {
        $platform = $this->createPlatformMock('sqlite');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::any())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql): int {
                if ('UPDATE mailbox SET inbox_folder_id = NULL' === $sql) {
                    throw new \RuntimeException('ignored');
                }

                if ('TRUNCATE address' === $sql) {
                    throw new \RuntimeException('fallback');
                }

                return 1;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getConnection')->willReturn($connection);

        $tester = new CommandTester(new MailClearCommand($entityManager));

        self::assertSame(Command::SUCCESS, $tester->execute(['--yes' => true]));
        self::assertStringContainsString('Leere Tabelle address', $tester->getDisplay());
        self::assertStringContainsString('Alle Tabellen wurden erfolgreich geleert.', $tester->getDisplay());
    }

    public function testMailClearCommandFallsBackToDeleteForPostgresql(): void
    {
        $tables = [
            'email_address_link',
            'email_attachment_link',
            'email_body_link',
            'email_folder',
            'mailbox_folder',
            'address',
            'attachment',
            'body',
            'email',
            'folder',
        ];

        $platform = $this->createPlatformMock('postgresql');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::exactly(2))->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::any())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use ($tables): int {
                if (str_starts_with($sql, 'TRUNCATE ')) {
                    throw new \RuntimeException('truncate failed');
                }

                if (in_array($sql, array_map(static fn (string $table): string => 'DELETE FROM '.$table, $tables), true)) {
                    return 1;
                }

                return 1;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getConnection')->willReturn($connection);

        $tester = new CommandTester(new MailClearCommand($entityManager));

        self::assertSame(Command::SUCCESS, $tester->execute(['--yes' => true]));
        self::assertStringContainsString('Versuche DELETE FROM', $tester->getDisplay());
        self::assertStringContainsString('Alle Tabellen mit DELETE gelöscht.', $tester->getDisplay());
    }

    public function testMailClearCommandRestoresMysqlForeignKeyChecksOnFailure(): void
    {
        $platform = $this->createPlatformMock('mysql');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::any())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql): int {
                if ('DELETE FROM email_address_link' === $sql) {
                    throw new \RuntimeException('cannot delete');
                }

                if ('TRUNCATE email_address_link' === $sql) {
                    throw new \RuntimeException('cannot truncate');
                }

                return 1;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getConnection')->willReturn($connection);

        $tester = new CommandTester(new MailClearCommand($entityManager));

        self::assertSame(Command::FAILURE, $tester->execute(['--yes' => true]));
        self::assertStringContainsString('Fehler beim Leeren der Tabellen', $tester->getDisplay());
    }

    public function testMailSendCommandReportsWhenNoPendingEmailsExist(): void
    {
        $repository = $this->createMock(EmailRepository::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->with([
                'isOutgoing' => true,
                'isSent' => false,
                'isDraft' => false,
                'isSending' => false,
            ])
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::never())->method('syncEmailSystemFolders');
        $attachmentStorage = $this->createStub(AttachmentStorage::class);

        $tester = new CommandTester(new MailSendCommand($repository, $entityManager, $folderManager, $attachmentStorage, $this->createStub(SmtpMailerFactory::class), new AllowAllLicenseEnforcement()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Keine ausstehenden E-Mails zum Versenden gefunden.', $tester->getDisplay());
    }

    public function testMailSendCommandSendsEligibleEmailsAndSkipsFutureScheduledMessages(): void
    {
        $futureEmail = (new Email())
            ->setSubject('Later')
            ->setIsOutgoing(true)
            ->setIsDraft(false)
            ->setScheduledAt(new \DateTimeImmutable('+1 day'));

        $sendableEmail = $this->createOutgoingEmail((new MailboxConnector())
            ->setName('SMTP')
            ->setEmail('smtp@example.com')
            ->setSmtpHost('127.0.0.1')
            ->setSmtpPort(2525));

        $repository = $this->createMock(EmailRepository::class);
        $repository->expects(self::once())->method('findBy')->willReturn([$futureEmail, $sendableEmail]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('flush');

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::once())
            ->method('syncEmailSystemFolders')
            ->with($sendableEmail);
        $attachmentStorage = $this->createStub(AttachmentStorage::class);

        $mailerFactory = $this->createMock(SmtpMailerFactory::class);
        $mailerFactory->expects(self::once())
            ->method('fromConnector')
            ->willReturn(new Mailer(new NullTransport()));

        $tester = new CommandTester(new MailSendCommand($repository, $entityManager, $folderManager, $attachmentStorage, $mailerFactory, new AllowAllLicenseEnforcement()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertTrue($sendableEmail->isSent());
        self::assertFalse($sendableEmail->isSending());
        self::assertInstanceOf(\DateTimeImmutable::class, $sendableEmail->getSentAt());
        self::assertFalse($futureEmail->isSent());
        self::assertStringContainsString('Mail erfolgreich versendet.', $tester->getDisplay());
    }

    public function testMailSendCommandHandlesMissingSmtpConfiguration(): void
    {
        $repository = $this->createStub(EmailRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::never())->method('syncEmailSystemFolders');
        $attachmentStorage = $this->createStub(AttachmentStorage::class);

        $command = new MailSendCommand($repository, $entityManager, $folderManager, $attachmentStorage, $this->createStub(SmtpMailerFactory::class), new AllowAllLicenseEnforcement());
        $output = new BufferedOutput();

        $this->invokeMailSend($command, (new Email())->setSubject('Missing config'), $output);

        self::assertStringContainsString('Keine SMTP-Konfiguration', $output->fetch());
    }

    public function testMailSendCommandResetsSendingFlagAfterTransportFailuresForTlsAndSsl(): void
    {
        $repository = $this->createStub(EmailRepository::class);
        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::never())->method('syncEmailSystemFolders');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(4))->method('flush');
        $attachmentStorage = $this->createStub(AttachmentStorage::class);

        $failingMailer = $this->createStub(MailerInterface::class);
        $failingMailer->method('send')->willThrowException(new \RuntimeException('SMTP unavailable'));
        $mailerFactory = $this->createMock(SmtpMailerFactory::class);
        $mailerFactory->expects(self::exactly(2))
            ->method('fromConnector')
            ->willReturn($failingMailer);

        $command = new MailSendCommand($repository, $entityManager, $folderManager, $attachmentStorage, $mailerFactory, new AllowAllLicenseEnforcement());

        foreach (['tls', 'ssl'] as $security) {
            $email = $this->createOutgoingEmail((new MailboxConnector())
                ->setName('SMTP')
                ->setEmail('smtp@example.com')
                ->setSmtpHost('127.0.0.1')
                ->setSmtpPort(2525)
                ->setSmtpSecurity($security));
            $output = new BufferedOutput();

            $this->invokeMailSend($command, $email, $output);

            self::assertFalse($email->isSent());
            self::assertFalse($email->isSending());
            self::assertStringContainsString('Fehler beim Senden', $output->fetch());
        }
    }

    public function testPurgeCssCommandHandlesMissingInputFile(): void
    {
        $collector = $this->createMock(CssClassCollector::class);
        $collector->expects(self::never())->method('collectAll');

        $tester = new CommandTester(new PurgeCssCommand($collector, new CssPurgerFactory()));

        self::assertSame(Command::FAILURE, $tester->execute([
            'input' => 'var/does-not-exist.css',
        ]));
        self::assertStringContainsString('Eingabedatei nicht gefunden', $tester->getDisplay());
    }

    public function testPurgeCssCommandSupportsDryRunJsonAndOverwriteModes(): void
    {
        $collector = $this->createMock(CssClassCollector::class);
        $collector->expects(self::exactly(2))
            ->method('collectAll')
            ->willReturn(['btn', ' alert ', 'invalid selector!', '.nav-link']);

        $command = new PurgeCssCommand($collector, new CssPurgerFactory());
        $suffix = bin2hex(random_bytes(4));
        $inputPath = self::varDir().'/purge-'.$suffix.'.css';

        self::assertNotFalse(file_put_contents($inputPath, '.btn{color:red}.unused{color:blue}.nav-link{display:block}'));

        try {
            $dryRun = new CommandTester($command);
            self::assertSame(Command::SUCCESS, $dryRun->execute([
                'input' => $inputPath,
                '--dry-run' => true,
                '--json' => true,
                '--debug-selectors' => true,
                '--safelist' => ['custom-one, .custom-two', ''],
            ]));
            self::assertStringContainsString('"output": null', $dryRun->getDisplay());
            self::assertStringContainsString('.custom-two', $dryRun->getDisplay());
            self::assertStringContainsString('.custom-one', $dryRun->getDisplay());

            $overwrite = new CommandTester($command);
            self::assertSame(Command::SUCCESS, $overwrite->execute([
                'input' => $inputPath,
                '--overwrite' => true,
            ]));
            self::assertFileExists($inputPath.'.bak');
            self::assertStringContainsString('Backup erstellt', $overwrite->getDisplay());
            self::assertStringContainsString('Purged CSS geschrieben nach', $overwrite->getDisplay());
        } finally {
            @unlink($inputPath);
            @unlink($inputPath.'.bak');
        }
    }

    public function testPurgeCssCommandCoversHelperMethodsAndNonStringInputFallbacks(): void
    {
        self::assertFileExists(dirname(__DIR__, 2).'/assets/css/bootstrap.min.css');

        $collector = $this->createMock(CssClassCollector::class);
        $collector->expects(self::once())->method('collectAll')->willReturn([]);

        $command = new class($collector, new CssPurgerFactory()) extends PurgeCssCommand {
            public function runExecute(InputInterface $input, OutputInterface $output): int
            {
                return $this->execute($input, $output);
            }
        };

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')
            ->willReturnMap([
                ['input', 123],
                ['output', new \stdClass()],
            ]);
        $input->method('getOption')
            ->willReturnMap([
                ['overwrite', false],
                ['dry-run', true],
                ['json', false],
                ['debug-selectors', false],
                ['safelist', 'not-an-array'],
            ]);

        $buffer = new BufferedOutput();

        self::assertSame(Command::SUCCESS, $command->runExecute($input, $buffer));
        self::assertSame('/tmp/demo.css', $this->invokePurgeHelper($command, 'toAbsolutePath', ['/tmp/demo.css', '/app']));
        self::assertSame('/app', $this->invokePurgeHelper($command, 'toAbsolutePath', ['', '/app']));
        self::assertSame('var/demo.css', $this->invokePurgeHelper($command, 'toRelativePath', ['/app/var/demo.css', '/app']));
        self::assertSame('/outside/demo.css', $this->invokePurgeHelper($command, 'toRelativePath', ['/outside/demo.css', '/app']));
        self::assertSame('demo', $this->invokePurgeHelper($command, 'normalizeClassName', ['.demo']));
        self::assertNull($this->invokePurgeHelper($command, 'normalizeClassName', ['bad selector!']));
        self::assertNull($this->invokePurgeHelper($command, 'normalizeClassName', ["'\"  "]));
    }

    public function testMailFetchCommandProcessesSingleMailboxResolvedById(): void
    {
        $mailbox = (new Mailbox())->setName('Support')->setEmail('support@example.com');
        $connector = (new MailboxConnector())->setName('Primary')->setEmail('connector@example.com');
        $mailbox->addConnector($connector);

        $mailboxRepository = $this->createMock(MailboxRepository::class);
        $mailboxRepository->expects(self::once())->method('find')->with('mailbox-id')->willReturn($mailbox);
        $mailboxRepository->expects(self::never())->method('findAll');

        $fetcher = $this->createMock(Pop3Fetcher::class);
        $fetcher->expects(self::once())->method('setOutput');
        $fetcher->expects(self::once())
            ->method('fetchForConnector')
            ->willReturn(['imported' => 2, 'skipped' => 0, 'errors' => 0]);

        $tester = new CommandTester(new MailFetchCommand($mailboxRepository, $fetcher));
        self::assertSame(Command::SUCCESS, $tester->execute(['--mailbox' => 'mailbox-id']));
        self::assertStringContainsString('Gesamt: importiert=2', $tester->getDisplay());
    }

    public function testMailSendCommandAttachesFilesAndSkipsNullAttachments(): void
    {
        $email = $this->createOutgoingEmail((new MailboxConnector())
            ->setName('SMTP')
            ->setEmail('smtp@example.com')
            ->setSmtpHost('127.0.0.1')
            ->setSmtpPort(2525));
        $email->addEmailAttachment(
            (new EmailAttachmentLink())->setAttachment(
                (new Attachment())
                    ->setName('doc.pdf')
                    ->setSize(10)
                    ->setMimeType('application/pdf')
                    ->setSha1('sha1')
                    ->setFilePath('pending/doc.pdf')
            )
        );
        $email->addEmailAttachment(new EmailAttachmentLink());

        $repository = $this->createMock(EmailRepository::class);
        $repository->expects(self::once())->method('findBy')->willReturn([$email]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('flush');

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::once())->method('syncEmailSystemFolders')->with($email);

        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $attachmentStorage->method('resolveAbsolutePath')->willReturnArgument(0);

        $mailerFactory = $this->createMock(SmtpMailerFactory::class);
        $mailerFactory->expects(self::once())->method('fromConnector')->willReturn(new Mailer(new NullTransport()));

        $tester = new CommandTester(new MailSendCommand($repository, $entityManager, $folderManager, $attachmentStorage, $mailerFactory, new AllowAllLicenseEnforcement()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertTrue($email->isSent());
    }

    public function testMailClearCommandTruncatesPostgresqlSuccessfully(): void
    {
        $platform = $this->createPlatformMock('postgresql');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::any())->method('executeStatement')->willReturn(1);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getConnection')->willReturn($connection);

        $tester = new CommandTester(new MailClearCommand($entityManager));

        self::assertSame(Command::SUCCESS, $tester->execute(['--yes' => true]));
        self::assertStringContainsString('Leere alle Tabellen in PostgreSQL', $tester->getDisplay());
        self::assertStringContainsString('Alle Tabellen wurden erfolgreich geleert.', $tester->getDisplay());
    }

    public function testMailClearCommandClearsMysqlTablesSuccessfully(): void
    {
        $platform = $this->createPlatformMock('mysql');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::any())->method('executeStatement')->willReturn(1);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getConnection')->willReturn($connection);

        $tester = new CommandTester(new MailClearCommand($entityManager));

        self::assertSame(Command::SUCCESS, $tester->execute(['--yes' => true]));
        self::assertStringContainsString('Leere Tabelle', $tester->getDisplay());
        self::assertStringContainsString('Alle Tabellen wurden erfolgreich geleert.', $tester->getDisplay());
    }

    public function testMailClearCommandRestoresSqliteForeignKeysOnFailure(): void
    {
        $platform = $this->createPlatformMock('sqlite');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::any())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql): int {
                if ('DELETE FROM address' === $sql) {
                    throw new \RuntimeException('cannot clear');
                }

                return 1;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getConnection')->willReturn($connection);

        $tester = new CommandTester(new MailClearCommand($entityManager));

        self::assertSame(Command::FAILURE, $tester->execute(['--yes' => true]));
        self::assertStringContainsString('Fehler beim Leeren der Tabellen', $tester->getDisplay());
    }

    public function testMailClearCommandRestoresPostgresqlReplicationRoleOnFailure(): void
    {
        $platform = $this->createPlatformMock('postgresql');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::exactly(2))->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::exactly(2))->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::exactly(2))->method('rollBack');
        $connection->expects(self::any())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql): int {
                if (str_starts_with($sql, 'TRUNCATE ')) {
                    throw new \RuntimeException('truncate failed');
                }
                if ('DELETE FROM email_address_link' === $sql) {
                    throw new \RuntimeException('delete failed');
                }

                return 1;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getConnection')->willReturn($connection);

        $tester = new CommandTester(new MailClearCommand($entityManager));

        self::assertSame(Command::FAILURE, $tester->execute(['--yes' => true]));
        self::assertStringContainsString('Fehler beim Leeren der Tabellen', $tester->getDisplay());
    }

    public function testCompileScssCommandFailsWhenOutputDirectoryCannotBeCreated(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $inputPath = self::varDir().'/compile-dir-'.$suffix.'.scss';
        $blockerPath = self::varDir().'/compile-blocker-'.$suffix;

        self::assertNotFalse(file_put_contents($inputPath, '.box { color: #123456; }'));
        self::assertNotFalse(file_put_contents($blockerPath, 'blocker'));

        try {
            $tester = new CommandTester(new CompileScssCommand());
            self::assertSame(Command::FAILURE, $tester->execute([
                'input' => $inputPath,
                'output' => $blockerPath.'/out.css',
            ]));
            self::assertStringContainsString('Failed to create output directory', $tester->getDisplay());
        } finally {
            @unlink($inputPath);
            @unlink($blockerPath);
        }
    }

    public function testPurgeCssCommandWritesToExplicitOutputPathWithDebugSelectors(): void
    {
        $collector = $this->createMock(CssClassCollector::class);
        $collector->expects(self::once())->method('collectAll')->willReturn(['btn']);

        $command = new PurgeCssCommand($collector, new CssPurgerFactory());
        $suffix = bin2hex(random_bytes(4));
        $inputPath = self::varDir().'/purge-out-'.$suffix.'.css';
        $outputPath = self::varDir().'/purge-explicit-'.$suffix.'.css';

        self::assertNotFalse(file_put_contents($inputPath, '.btn{color:red}.unused{color:blue}'));

        try {
            $tester = new CommandTester($command);
            self::assertSame(Command::SUCCESS, $tester->execute([
                'input' => $inputPath,
                'output' => $outputPath,
                '--debug-selectors' => true,
            ]));
            self::assertFileExists($outputPath);
            self::assertStringContainsString('Selektoren (gesamt', $tester->getDisplay());
            self::assertStringContainsString('Purged CSS geschrieben nach', $tester->getDisplay());
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    public function testPurgeCssCommandReportsPurgerInitializationFailure(): void
    {
        $collector = $this->createStub(CssClassCollector::class);
        $factory = $this->createStub(CssPurgerFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('init failed'));

        $command = new PurgeCssCommand($collector, $factory);
        $suffix = bin2hex(random_bytes(4));
        $inputPath = self::varDir().'/purge-init-'.$suffix.'.css';
        self::assertNotFalse(file_put_contents($inputPath, '.btn{color:red}'));

        try {
            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $inputPath]));
            self::assertStringContainsString('Initialisierung des Vendor-CSS-Purgers fehlgeschlagen', $tester->getDisplay());
        } finally {
            @unlink($inputPath);
        }
    }

    public function testPurgeCssCommandReportsProcessingFailure(): void
    {
        $collector = $this->createStub(CssClassCollector::class);
        $purger = $this->createStub(CssPurger::class);
        $purger->method('loadContent')->willThrowException(new \RuntimeException('load failed'));
        $factory = $this->createStub(CssPurgerFactory::class);
        $factory->method('create')->willReturn($purger);

        $command = new PurgeCssCommand($collector, $factory);
        $suffix = bin2hex(random_bytes(4));
        $inputPath = self::varDir().'/purge-proc-'.$suffix.'.css';
        self::assertNotFalse(file_put_contents($inputPath, '.btn{color:red}'));

        try {
            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $inputPath]));
            self::assertStringContainsString('Verarbeitung der CSS-Datei im Vendor-Purger fehlgeschlagen', $tester->getDisplay());
        } finally {
            @unlink($inputPath);
        }
    }

    public function testPurgeCssCommandReportsOutputGenerationFailure(): void
    {
        $collector = $this->createStub(CssClassCollector::class);
        $collector->method('collectAll')->willReturn(['btn']);
        $purger = $this->createStub(CssPurger::class);
        $purger->method('generateOutput')->willThrowException(new \RuntimeException('output failed'));
        $factory = $this->createStub(CssPurgerFactory::class);
        $factory->method('create')->willReturn($purger);

        $command = new PurgeCssCommand($collector, $factory);
        $suffix = bin2hex(random_bytes(4));
        $inputPath = self::varDir().'/purge-gen-'.$suffix.'.css';
        self::assertNotFalse(file_put_contents($inputPath, '.btn{color:red}'));

        try {
            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $inputPath]));
            self::assertStringContainsString('Erzeugen der Ausgabedaten fehlgeschlagen', $tester->getDisplay());
        } finally {
            @unlink($inputPath);
        }
    }

    public function testPurgeCssCommandReportsWhenOutputDirectoryCannotBeCreated(): void
    {
        $collector = $this->createStub(CssClassCollector::class);
        $command = new PurgeCssCommand($collector, new CssPurgerFactory());
        $suffix = bin2hex(random_bytes(4));
        $inputPath = self::varDir().'/purge-md-'.$suffix.'.css';
        $blockerPath = self::varDir().'/purge-md-blocker-'.$suffix;

        self::assertNotFalse(file_put_contents($inputPath, '.btn{color:red}'));
        self::assertNotFalse(file_put_contents($blockerPath, 'blocker'));

        try {
            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $tester->execute([
                'input' => $inputPath,
                'output' => $blockerPath.'/out.css',
            ]));
            self::assertStringContainsString('Konnte Zielverzeichnis nicht anlegen', $tester->getDisplay());
        } finally {
            @unlink($inputPath);
            @unlink($blockerPath);
        }
    }

    public function testPurgeCssCommandWarnsWhenBackupCannotBeCreated(): void
    {
        $collector = $this->createStub(CssClassCollector::class);
        $collector->method('collectAll')->willReturn(['btn']);
        $command = new PurgeCssCommand($collector, new CssPurgerFactory());
        $suffix = bin2hex(random_bytes(4));
        $inputPath = self::varDir().'/purge-bak-'.$suffix.'.css';
        $bakDir = $inputPath.'.bak';

        self::assertNotFalse(file_put_contents($inputPath, '.btn{color:red}.unused{color:blue}'));
        self::assertTrue(mkdir($bakDir));

        try {
            $tester = new CommandTester($command);
            self::assertSame(Command::SUCCESS, $tester->execute([
                'input' => $inputPath,
                '--overwrite' => true,
            ]));
            self::assertStringContainsString('Backup konnte nicht erstellt werden', $tester->getDisplay());
        } finally {
            @rmdir($bakDir);
            @unlink($inputPath);
        }
    }

    public function testPurgeCssCommandReportsWhenOutputFileCannotBeWritten(): void
    {
        $collector = $this->createStub(CssClassCollector::class);
        $collector->method('collectAll')->willReturn(['btn']);
        $command = new PurgeCssCommand($collector, new CssPurgerFactory());
        $suffix = bin2hex(random_bytes(4));
        $inputPath = self::varDir().'/purge-write-'.$suffix.'.css';
        $outputDir = self::varDir().'/purge-write-out-'.$suffix.'.css';

        self::assertNotFalse(file_put_contents($inputPath, '.btn{color:red}.unused{color:blue}'));
        self::assertTrue(mkdir($outputDir));

        try {
            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $tester->execute([
                'input' => $inputPath,
                'output' => $outputDir,
            ]));
            self::assertStringContainsString('Schreiben der Ausgabedatei fehlgeschlagen', $tester->getDisplay());
        } finally {
            @rmdir($outputDir);
            @unlink($inputPath);
        }
    }

    /**
     * Repository-local var directory, created on demand: tests must not
     * depend on a pre-existing /app/var nor on the process working directory.
     */
    private static function varDir(): string
    {
        $dir = dirname(__DIR__, 2).'/var';

        if (!is_dir($dir)) {
            self::assertTrue(@mkdir($dir, 0777, true) || is_dir($dir));
        }

        return $dir;
    }

    private function createPlatformMock(string $name): AbstractPlatform
    {
        return match ($name) {
            'sqlite' => new SQLitePlatform(),
            'postgresql' => new PostgreSQLPlatform(),
            'mysql' => new MySQLPlatform(),
            default => $this->createMock(AbstractPlatform::class),
        };
    }

    private function createOutgoingEmail(MailboxConnector $connector): Email
    {
        $mailbox = (new Mailbox())
            ->setName('Mailbox')
            ->setEmail('mailbox@example.com');
        $mailbox->addConnector($connector->setIsPrimary(true));

        $email = (new Email())
            ->setSubject('Test mail')
            ->setIsOutgoing(true)
            ->setIsDraft(false)
            ->setMailbox($mailbox);

        $email->addEmailAddress(
            (new EmailAddressLink())
                ->setType(EmailAddressType::SENDER)
                ->setAddress((new Address())->setEmail('sender@example.com')->setName('Sender'))
        );
        $email->addEmailAddress(
            (new EmailAddressLink())
                ->setType(EmailAddressType::RECIPIENT)
                ->setAddress((new Address())->setEmail('recipient@example.com'))
        );
        $email->addEmailAddress(
            (new EmailAddressLink())
                ->setType(EmailAddressType::CC)
                ->setAddress((new Address())->setEmail('cc@example.com'))
        );
        $email->addEmailAddress(
            (new EmailAddressLink())
                ->setType(EmailAddressType::BCC)
                ->setAddress((new Address())->setEmail('bcc@example.com'))
        );
        $email->addEmailAddress(
            (new EmailAddressLink())
                ->setType(EmailAddressType::REPLY_TO)
                ->setAddress((new Address())->setEmail('reply@example.com'))
        );
        $email->addEmailAddress((new EmailAddressLink())->setType(EmailAddressType::RECIPIENT));

        $email->addEmailBody(
            (new EmailBodyLink())
                ->setContentType(BodyContentType::TEXT)
                ->setBody((new Body())->setContent('Plain body'))
        );
        $email->addEmailBody(
            (new EmailBodyLink())
                ->setContentType(BodyContentType::HTML)
                ->setBody((new Body())->setContent('<p>HTML body</p>'))
        );
        $email->addEmailBody((new EmailBodyLink())->setContentType(BodyContentType::TEXT));

        return $email;
    }

    private function invokeMailSend(MailSendCommand $command, Email $email, BufferedOutput $output): void
    {
        $closure = \Closure::bind(
            static function (MailSendCommand $command, Email $email, BufferedOutput $output): void {
                $command->sendEmail($email, $output);
            },
            null,
            MailSendCommand::class,
        );
        self::assertInstanceOf(\Closure::class, $closure);
        $closure($command, $email, $output);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokePurgeHelper(PurgeCssCommand $command, string $method, array $arguments): mixed
    {
        $closure = \Closure::bind(
            static function (PurgeCssCommand $command, string $method, array $arguments): mixed {
                return $command->{$method}(...$arguments);
            },
            null,
            PurgeCssCommand::class,
        );
        self::assertInstanceOf(\Closure::class, $closure);

        return $closure($command, $method, $arguments);
    }
}

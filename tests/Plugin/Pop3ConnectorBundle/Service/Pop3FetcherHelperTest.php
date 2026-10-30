<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Plugin\Pop3ConnectorBundle\Service;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailRoutingService;
use Doctrine\ORM\EntityManagerInterface;
use JBSNewMedia\Pop3ConnectorBundle\Service\Pop3Fetcher;
use JBSNewMedia\Pop3ConnectorBundle\Service\Pop3StorageFactory;
use Laminas\Mail\Header\HeaderInterface;
use Laminas\Mail\Headers;
use Laminas\Mail\Storage\Message as StorageMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

final class Pop3FetcherHelperTest extends TestCase
{
    private Pop3Fetcher $fetcher;

    protected function setUp(): void
    {
        Pop3FetcherTestFunctionOverrides::$forceMissingIconvMimeDecode = false;
        Pop3FetcherTestFunctionOverrides::$forceMbDetectEncodingFailure = false;

        $this->fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $this->createStub(Pop3StorageFactory::class),
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );
    }

    public function testSetOutputReturnsSameFetcherAndWritesLogMessages(): void
    {
        $output = $this->createMock(OutputInterface::class);
        $output
            ->expects(self::once())
            ->method('writeln')
            ->with('<error>   POP3 configuration incomplete.</error>');

        self::assertSame($this->fetcher, $this->fetcher->setOutput($output));
        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 0], $this->fetcher->fetch(new Connector(), [], self::request()));
    }

    public function testSafeHeaderReturnsNullOnFailureAndDecodeHeaderCanUseMbDecodeFallback(): void
    {
        $message = $this->createStub(StorageMessage::class);
        $message->method('getHeaders')->willThrowException(new \RuntimeException('headers unavailable'));

        self::assertNull($this->callPrivateMethod('safeHeader', [$message, 'subject']));

        Pop3FetcherTestFunctionOverrides::$forceMissingIconvMimeDecode = true;

        self::assertSame('Jürgen', $this->callPrivateMethod('decodeHeaderValue', ['=?UTF-8?Q?J=C3=BCrgen?=']));
    }

    public function testToUtf8UsesIsoFallbackWhenEncodingDetectionFails(): void
    {
        Pop3FetcherTestFunctionOverrides::$forceMbDetectEncodingFailure = true;

        self::assertSame('Jürgen', $this->callPrivateMethod('toUtf8', ["J\xfcrgen"]));
    }

    public function testConvertHtmlToTextRemovesMarkupAndNormalizesWhitespace(): void
    {
        $result = $this->callPrivateMethod('convertHtmlToText', [
            '<head><style>body{}</style></head><script>alert(1)</script><div>Hello<br><strong>World</strong></div>',
        ]);

        self::assertSame('Hello World', $result);
    }

    public function testGetPartContentDecodesTransferEncodingsAndCharset(): void
    {
        $base64Part = $this->createPartStub('SGVsbG8=', 'text/plain; charset=UTF-8', 'base64');
        $qpPart = $this->createPartStub('Hello=20World', 'text/plain; charset=UTF-8', 'quoted-printable');
        $latin1Part = $this->createPartStub("J\xfcrgen", 'text/plain; charset=ISO-8859-1', '');
        $invalidCharsetPart = $this->createPartStub("J\xfcrgen", 'text/plain; charset=NO_SUCH_CHARSET', '');
        $withoutCharsetPart = $this->createPartStub("J\xfcrgen", 'text/plain', '');
        $brokenPart = $this->createStub(StorageMessage::class);
        $brokenPart->method('getContent')->willThrowException(new \RuntimeException('unreadable'));

        self::assertSame('Hello', $this->callPrivateMethod('getPartContent', [$base64Part]));
        self::assertSame('Hello World', $this->callPrivateMethod('getPartContent', [$qpPart]));
        self::assertSame('Jürgen', $this->callPrivateMethod('getPartContent', [$latin1Part]));
        self::assertSame('Jürgen', $this->callPrivateMethod('getPartContent', [$invalidCharsetPart]));
        self::assertSame('Jürgen', $this->callPrivateMethod('getPartContent', [$withoutCharsetPart]));
        self::assertSame('', $this->callPrivateMethod('getPartContent', [$brokenPart]));
    }

    public function testDetectFileNameReadsDispositionAndTypeHeaders(): void
    {
        $dispositionPart = $this->createPartStub('', 'application/pdf; name="ignored.pdf"', '', 'attachment; filename="angebot.pdf"');
        $typePart = $this->createPartStub('', 'application/pdf; filename="plan.pdf"');
        $stringHeaderPart = $this->createStub(StorageMessage::class);
        $stringHeaderPart->method('getHeader')->willReturn('attachment; filename="string.txt"');
        $throwingPart = $this->createStub(StorageMessage::class);
        $throwingPart->method('getHeader')->willThrowException(new \RuntimeException('missing'));
        $emptyPart = $this->createStub(StorageMessage::class);
        $emptyPart->method('getHeader')->willReturn(null);

        self::assertSame('angebot.pdf', $this->callPrivateMethod('detectFileName', [$dispositionPart]));
        self::assertSame('plan.pdf', $this->callPrivateMethod('detectFileName', [$typePart]));
        self::assertSame('string.txt', $this->callPrivateMethod('detectFileName', [$stringHeaderPart]));
        self::assertNull($this->callPrivateMethod('detectFileName', [$throwingPart]));
        self::assertNull($this->callPrivateMethod('detectFileName', [$emptyPart]));
    }

    public function testStoreAttachmentPartPersistsAttachmentAndSkipsFailedStorage(): void
    {
        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });

        $attachmentStorage = $this->createMock(AttachmentStorage::class);
        $attachmentStorage
            ->expects(self::exactly(2))
            ->method('store')
            ->willReturnCallback(static function () {
                static $call = 0;
                ++$call;

                if (1 === $call) {
                    return 'stored/file.txt';
                }

                throw new \RuntimeException('disk full');
            });

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $attachmentStorage,
            $this->createStub(Pop3StorageFactory::class),
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );
        $email = new Email();
        $part = $this->createPartStub('payload', 'text/plain; charset=UTF-8', '', 'attachment; filename="file.txt"');

        $this->callPrivateMethodOn($fetcher, 'storeAttachmentPart', [$part, $email, 'text/plain']);
        $this->callPrivateMethodOn($fetcher, 'storeAttachmentPart', [$part, $email, 'text/plain']);

        self::assertCount(1, $email->getEmailAttachments());
        self::assertCount(2, $persisted);
    }

    public function testDumpOriginalMessageWritesPendingAndFinalFilesAndRemovesPendingDump(): void
    {
        $connector = (new Connector())
            ->setName('Support')
            ->setDomain('example.com');
        $email = (new Email())
            ->setReceivedAt(new \DateTimeImmutable('2024-01-02 03:04:05'))
            ->setContentHash('hash123');
        $message = $this->createMessageStub(
            "Date: Tue, 02 Jan 2024 03:04:05 +0000\nSubject: Test",
            'body'
        );

        $tempDir = sys_get_temp_dir().'/pop3-fetcher-helper-'.bin2hex(random_bytes(4));
        $dumpDir = $tempDir.'/mail_dumps';
        $fetcher = $this->createFetcherWithDumpDir($dumpDir);

        try {
            $this->callPrivateMethodOn($fetcher, 'dumpOriginalMessage', [$message, 'example.com', null, 'account:uid-1', 'pending']);
            self::assertFileExists($dumpDir.'/example.com/2024/01/02/pending/account_uid-1.eml');
            self::assertInstanceOf(\DateTimeImmutable::class, $this->callPrivateMethod('extractDateFromMessage', [$message]));

            $this->callPrivateMethodOn($fetcher, 'dumpOriginalMessage', [$message, 'example.com', $email, 'account:uid-1', 'success']);

            self::assertFileDoesNotExist($dumpDir.'/example.com/2024/01/02/pending/account_uid-1.eml');
            self::assertFileExists($dumpDir.'/example.com/2024/01/02/success/hash123.eml');
            self::assertSame('example.com', $this->callPrivateMethod('getConnectorDumpKey', [$connector, 'support']));

            $emailWithoutHash = (new Email())
                ->setId(Uuid::v7())
                ->setReceivedAt(new \DateTimeImmutable('2024-01-02 03:04:05'));
            $this->callPrivateMethodOn($fetcher, 'dumpOriginalMessage', [$message, 'example.com', null, 'account:uid-2', 'pending']);
            $this->callPrivateMethodOn($fetcher, 'dumpOriginalMessage', [$message, 'example.com', $emailWithoutHash, 'account:uid-2', 'success']);
            self::assertFileExists($dumpDir.'/example.com/2024/01/02/success/'.$emailWithoutHash->getId().'.eml');
            self::assertFileDoesNotExist($dumpDir.'/example.com/2024/01/02/pending/account_uid-2.eml');

            // Nothing may be written relative to the working directory.
            self::assertDirectoryDoesNotExist((getcwd() ?: __DIR__).'/mail_dumps/example.com/2024/01/02');

            $invalidDateMessage = $this->createMessageStub('Date: invalid', 'body', 'invalid-date');
            self::assertNull($this->callPrivateMethod('extractDateFromMessage', [$invalidDateMessage]));

            $withoutDateMessage = $this->createMessageStub('', 'body', null);
            self::assertNull($this->callPrivateMethod('extractDateFromMessage', [$withoutDateMessage]));
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    public function testDumpFileNameOnlyKeepsSafeCharactersAndLimitsLength(): void
    {
        self::assertSame('account_uid-1.eml', Pop3Fetcher::dumpFileName('account:uid-1'));
        self::assertSame('_.._.._etc_passwd.eml', Pop3Fetcher::dumpFileName('/../../etc/passwd'));
        self::assertSame('_.._escape.eml', Pop3Fetcher::dumpFileName('../../escape'));
        self::assertSame('a_b_c.eml', Pop3Fetcher::dumpFileName("a\0b\nc"));
        self::assertSame('unknown.eml', Pop3Fetcher::dumpFileName('...'));
        self::assertSame('unknown.eml', Pop3Fetcher::dumpFileName(''));

        $long = Pop3Fetcher::dumpFileName(str_repeat('x', 300));
        self::assertSame(str_repeat('x', 120).'.eml', $long);
    }

    public function testDumpUsesSanitizedFileNameForTraversalLikeImportKeys(): void
    {
        $tempDir = sys_get_temp_dir().'/pop3-fetcher-helper-'.bin2hex(random_bytes(4));
        $dumpDir = $tempDir.'/mail_dumps';
        $fetcher = $this->createFetcherWithDumpDir($dumpDir);
        $message = $this->createMessageStub("Date: Tue, 02 Jan 2024 03:04:05 +0000\nSubject: Test", 'body');

        try {
            $this->callPrivateMethodOn($fetcher, 'dumpOriginalMessage', [$message, 'example.com', null, '../../escape', 'pending']);

            self::assertFileExists($dumpDir.'/example.com/2024/01/02/pending/_.._escape.eml');
            self::assertFileDoesNotExist($dumpDir.'/example.com/escape.eml');
            self::assertFileDoesNotExist($dumpDir.'/escape.eml');
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    public function testOldDumpsArePrunedOnceWhenWritingANewDump(): void
    {
        $tempDir = sys_get_temp_dir().'/pop3-fetcher-helper-'.bin2hex(random_bytes(4));
        $dumpDir = $tempDir.'/mail_dumps';
        $fetcher = $this->createFetcherWithDumpDir($dumpDir, 14);
        $message = $this->createMessageStub("Date: Tue, 02 Jan 2024 03:04:05 +0000\nSubject: Test", 'body');

        $oldDir = $dumpDir.'/example.com/2023/01/01/success';
        $recentDir = $dumpDir.'/example.com/2024/01/01/success';
        $otherConnectorDir = $dumpDir.'/other.com/2023/01/01/success';
        foreach ([$oldDir, $recentDir, $otherConnectorDir] as $dir) {
            self::assertTrue(mkdir($dir, 0777, true) || is_dir($dir));
        }
        file_put_contents($oldDir.'/old.eml', 'old');
        touch($oldDir.'/old.eml', time() - 30 * 86400);
        file_put_contents($recentDir.'/recent.eml', 'recent');
        touch($recentDir.'/recent.eml', time() - 2 * 86400);
        file_put_contents($otherConnectorDir.'/other.eml', 'other');
        touch($otherConnectorDir.'/other.eml', time() - 30 * 86400);

        try {
            $this->callPrivateMethodOn($fetcher, 'dumpOriginalMessage', [$message, 'example.com', null, 'account:uid-1', 'pending']);

            self::assertFileDoesNotExist($oldDir.'/old.eml');
            self::assertDirectoryDoesNotExist($dumpDir.'/example.com/2023');
            self::assertFileExists($recentDir.'/recent.eml');
            self::assertFileExists($otherConnectorDir.'/other.eml');
            self::assertFileExists($dumpDir.'/example.com/2024/01/02/pending/account_uid-1.eml');

            // Pruning runs only once per connector and instance: a file that
            // becomes old afterwards survives further dumps in the same run.
            file_put_contents($recentDir.'/late.eml', 'late');
            touch($recentDir.'/late.eml', time() - 30 * 86400);
            $this->callPrivateMethodOn($fetcher, 'dumpOriginalMessage', [$message, 'example.com', null, 'account:uid-2', 'pending']);
            self::assertFileExists($recentDir.'/late.eml');
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    public function testNormalizeDumpKeyNeverYieldsPathSegments(): void
    {
        self::assertSame('unknown_connector', Pop3Fetcher::normalizeDumpKey(''));
        self::assertSame('unknown_connector', Pop3Fetcher::normalizeDumpKey('.'));
        self::assertSame('unknown_connector', Pop3Fetcher::normalizeDumpKey('..'));
        self::assertSame('unknown_connector', Pop3Fetcher::normalizeDumpKey('...'));
        self::assertSame('example.com', Pop3Fetcher::normalizeDumpKey('.example.com.'));
        self::assertSame('_.._etc', Pop3Fetcher::normalizeDumpKey('../../etc'));
        self::assertSame('_.._escape', Pop3Fetcher::normalizeDumpKey('..\\..\\escape'));
        self::assertSame('user@example.com', Pop3Fetcher::normalizeDumpKey('user@example.com'));
        self::assertSame(str_repeat('x', 120), Pop3Fetcher::normalizeDumpKey(str_repeat('x', 300)));
    }

    public function testConnectorDumpKeyFallsBackForTraversalLikeDomainAndUsername(): void
    {
        self::assertSame('unknown_connector', $this->callPrivateMethod('getConnectorDumpKey', [(new Connector())->setDomain('..'), 'ignored']));
        self::assertSame('unknown_connector', $this->callPrivateMethod('getConnectorDumpKey', [(new Connector())->setDomain(''), '..']));
        self::assertSame('_', $this->callPrivateMethod('getConnectorDumpKey', [(new Connector())->setDomain(''), '../..']));
        self::assertSame('_.._etc', $this->callPrivateMethod('getConnectorDumpKey', [(new Connector())->setDomain(''), '../../etc']));
        self::assertSame('example.com', $this->callPrivateMethod('getConnectorDumpKey', [(new Connector())->setDomain('example.com'), 'user']));
        self::assertSame('user@example.com', $this->callPrivateMethod('getConnectorDumpKey', [(new Connector())->setDomain(''), 'user@example.com']));
    }

    public function testPruningNeverLeavesTheDumpRoot(): void
    {
        $tempDir = sys_get_temp_dir().'/pop3-fetcher-helper-'.bin2hex(random_bytes(4));
        $dumpDir = $tempDir.'/mail_dumps';
        $outsideDir = $tempDir.'/outside/2000/01/01/success';
        self::assertTrue(mkdir($dumpDir, 0777, true) || is_dir($dumpDir));
        self::assertTrue(mkdir($outsideDir, 0777, true) || is_dir($outsideDir));
        $outsideFile = $outsideDir.'/old.eml';
        file_put_contents($outsideFile, 'old');
        touch($outsideFile, time() - 30 * 86400);
        $link = $dumpDir.'/linked';
        self::assertTrue(symlink($tempDir.'/outside', $link));

        $fetcher = $this->createFetcherWithDumpDir($dumpDir, 14);

        try {
            $this->callPrivateMethodOn($fetcher, 'pruneOldDumps', ['linked']);
            self::assertFileExists($outsideFile);

            $this->callPrivateMethodOn($fetcher, 'pruneOldDumps', ['../outside']);
            self::assertFileExists($outsideFile);
        } finally {
            unlink($link);
            $this->removeDirectory($tempDir);
        }
    }

    public function testRetentionOfZeroDisablesPruning(): void
    {
        $tempDir = sys_get_temp_dir().'/pop3-fetcher-helper-'.bin2hex(random_bytes(4));
        $dumpDir = $tempDir.'/mail_dumps';
        $fetcher = $this->createFetcherWithDumpDir($dumpDir, 0);
        $message = $this->createMessageStub("Date: Tue, 02 Jan 2024 03:04:05 +0000\nSubject: Test", 'body');
        $oldDir = $dumpDir.'/example.com/2023/01/01/success';
        self::assertTrue(mkdir($oldDir, 0777, true) || is_dir($oldDir));
        file_put_contents($oldDir.'/old.eml', 'old');
        touch($oldDir.'/old.eml', time() - 365 * 86400);

        try {
            $this->callPrivateMethodOn($fetcher, 'dumpOriginalMessage', [$message, 'example.com', null, 'account:uid-1', 'pending']);

            self::assertFileExists($oldDir.'/old.eml');
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    public function testNormalizeAttachmentFilenameStripsPathsControlCharactersAndLimitsLength(): void
    {
        self::assertSame('attachment.bin', Pop3Fetcher::normalizeAttachmentFilename(null));
        self::assertSame('attachment.bin', Pop3Fetcher::normalizeAttachmentFilename(''));
        self::assertSame('attachment.bin', Pop3Fetcher::normalizeAttachmentFilename('..'));
        self::assertSame('attachment.bin', Pop3Fetcher::normalizeAttachmentFilename("\x00\x01 . "));
        self::assertSame('passwd', Pop3Fetcher::normalizeAttachmentFilename('../../etc/passwd'));
        self::assertSame('evil.exe', Pop3Fetcher::normalizeAttachmentFilename('C:\\Windows\\evil.exe'));
        self::assertSame('reportfinal.pdf', Pop3Fetcher::normalizeAttachmentFilename("report\r\nfinal.pdf\x7f"));
        self::assertSame('Angebot Müller.pdf', Pop3Fetcher::normalizeAttachmentFilename('Angebot Müller.pdf'));

        $long = Pop3Fetcher::normalizeAttachmentFilename(str_repeat('a', 300).'.pdf');
        self::assertSame(255, strlen($long));
        self::assertStringEndsWith('.pdf', $long);

        $longNoExt = Pop3Fetcher::normalizeAttachmentFilename(str_repeat('ü', 200));
        self::assertLessThanOrEqual(255, strlen($longNoExt));
        self::assertTrue(mb_check_encoding($longNoExt, 'UTF-8'));
    }

    public function testStoreAttachmentPartUsesNormalizedFileName(): void
    {
        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $attachmentStorage->method('store')->willReturn('ab/cd/file');
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $attachmentStorage,
            $this->createStub(Pop3StorageFactory::class),
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );
        $email = new Email();
        $part = $this->createPartStub('payload', 'application/octet-stream', '', "attachment; filename=\"../../evil\x01.bin\"");

        $this->callPrivateMethodOn($fetcher, 'storeAttachmentPart', [$part, $email, 'application/octet-stream']);

        $attachments = $email->getEmailAttachments();
        self::assertCount(1, $attachments);
        self::assertSame('evil.bin', $attachments->first()->getAttachment()->getName());
    }

    public function testFetchRejectsInvalidHostWithoutConnecting(): void
    {
        $storageFactory = $this->createMock(Pop3StorageFactory::class);
        $storageFactory->expects(self::never())->method('create');
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('writeln')->with(self::stringContains('Invalid POP3 host'));

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $storageFactory,
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );
        $fetcher->setOutput($output);

        $result = $fetcher->fetch(new Connector(), [
            'pop3Host' => 'pop.example.com/../x?y',
            'pop3Username' => 'user',
            'pop3Password' => 'secret',
        ], self::request());

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 0], $result);
    }

    public function testFetchWarnsWhenNoEncryptionIsConfigured(): void
    {
        $storageFactory = $this->createStub(Pop3StorageFactory::class);
        $storageFactory->method('create')->willThrowException(new \RuntimeException('no server'));
        $messages = [];
        $output = $this->createStub(OutputInterface::class);
        $output->method('writeln')->willReturnCallback(static function (string $line) use (&$messages): void {
            $messages[] = $line;
        });

        $fetcher = new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $storageFactory,
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );
        $fetcher->setOutput($output);

        $fetcher->fetch((new Connector())->setDomain('example.com'), [
            'pop3Host' => 'pop.example.com',
            'pop3Username' => 'user',
            'pop3Password' => 'secret',
            'pop3Security' => '',
        ], self::request());

        $joined = implode("\n", $messages);
        self::assertStringContainsString('without encryption', $joined);
        self::assertStringContainsString('pop.example.com:110', $joined);
    }

    private function createFetcherWithDumpDir(string $dumpDir, int $retentionDays = 14): Pop3Fetcher
    {
        return new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $this->createStub(Pop3StorageFactory::class),
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
            $dumpDir,
            $retentionDays,
        );
    }

    public function testDumpAndRemovePendingSwallowFilesystemAndConnectorFailures(): void
    {
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('writeln')->with(self::stringContains('Error dumping'));
        $this->fetcher->setOutput($output);

        $message = $this->createStub(StorageMessage::class);
        $message->method('getHeaders')->willThrowException(new \RuntimeException('cannot serialize'));
        $connector = $this->createStub(Connector::class);
        $connector->method('getDomain')->willReturn('example.com');

        $this->callPrivateMethod('dumpOriginalMessage', [$message, 'example.com', null, 'import:key']);

        // A broken key (null byte) must not escape as an exception.
        $this->callPrivateMethod('removePendingDump', ["broken\0key", 'import:key']);
    }

    public function testConvertMessageToEntityBuildsHtmlEmailAndFallbackInboxFolder(): void
    {
        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $folder = (new Folder())->setName('Postfach');
        $persisted = [];

        $addressRepository = $this->createStub(\Doctrine\ORM\EntityRepository::class);
        $addressRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($addressRepository);
        $entityManager
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::once())->method('syncEmailSystemFolders');
        $folderManager->expects(self::once())->method('ensureSystemFolder')->willReturn($folder);

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $this->createStub(EmailRepository::class),
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $this->createStub(Pop3StorageFactory::class),
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );
        $message = $this->createRichMessageStub(
            [
                'subject' => 'Subject line',
                'date' => 'Tue, 02 Jan 2024 03:04:05 +0000',
                'message-id' => '<abc@example.com>',
                'from' => 'Sender <sender@example.com>',
                'to' => 'Recipient <recipient@example.com>',
                'cc' => 'Copy <copy@example.com>',
                'bcc' => 'Blind <blind@example.com>',
                'reply-to' => 'Reply <reply@example.com>',
                'content-type' => 'text/html; charset=UTF-8',
            ],
            '<div>Hello <strong>world</strong></div>',
        );

        /** @var Email $email */
        $email = $this->callPrivateMethodOn($fetcher, 'convertMessageToEntity', [$message, $account, 'import-key']);

        self::assertSame('Subject line', $email->getSubject());
        self::assertSame('abc@example.com', $email->getMessageId());
        self::assertSame('Hello world', $email->getPreviewBody());
        self::assertCount(5, $email->getEmailAddresses());
        self::assertCount(1, $email->getEmailBodies());
        self::assertCount(1, $email->getEmailFolders());
        self::assertSame($folder, $email->getEmailFolders()->first()?->getFolder());
        self::assertGreaterThanOrEqual(7, count($persisted));
    }

    public function testConvertMessageToEntityBuildsTextPreviewAndHandlesInvalidDate(): void
    {
        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $account->setInboxFolder($folder = (new Folder())->setName('Inbox'));

        $address = (new Address())->setEmail('sender@example.com');
        $addressRepository = $this->createStub(\Doctrine\ORM\EntityRepository::class);
        $addressRepository->method('findOneBy')->willReturn($address);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($addressRepository);
        $entityManager->method('persist');

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::once())->method('syncEmailSystemFolders');
        $folderManager->expects(self::never())->method('ensureSystemFolder');

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $this->createStub(EmailRepository::class),
            $folderManager,
            $this->createStub(AttachmentStorage::class),
            $this->createStub(Pop3StorageFactory::class),
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );
        $message = $this->createRichMessageStub(
            [
                'subject' => 'Text only',
                'date' => 'not-a-date',
                'from' => 'sender@example.com',
                'content-type' => 'text/plain; charset=UTF-8',
            ],
            'Plain text body',
        );

        /** @var Email $email */
        $email = $this->callPrivateMethodOn($fetcher, 'convertMessageToEntity', [$message, $account, 'import-key']);

        self::assertNull($email->getReceivedAt());
        self::assertSame('Plain text body', $email->getPreviewBody());
        self::assertCount(1, $email->getEmailAddresses());
        self::assertSame(EmailAddressType::SENDER, $email->getEmailAddresses()->first()?->getType());
        self::assertSame($folder, $email->getEmailFolders()->first()?->getFolder());
    }

    public function testParseAddressesSkipsEmptyPartsAndLinkAddressIgnoresEmptyEmail(): void
    {
        self::assertSame(
            [['email' => 'one@example.com'], ['email' => 'two@example.com']],
            $this->callPrivateMethod('parseAddresses', [', one@example.com, , two@example.com, ']),
        );

        $email = new Email();
        $this->callPrivateMethod('linkAddress', [$email, ' <> ', null, EmailAddressType::SENDER]);

        self::assertCount(0, $email->getEmailAddresses());
    }

    public function testExtractPartsProcessesMultipartBodiesStatusAndAttachments(): void
    {
        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });

        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $attachmentStorage->method('store')->willReturn('stored/report.pdf');

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $attachmentStorage,
            $this->createStub(Pop3StorageFactory::class),
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );
        $rawMessage = implode("\r\n", [
            'Content-Type: multipart/mixed; boundary="pop3-boundary"',
            '',
            '--pop3-boundary',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Plain',
            '--pop3-boundary',
            'Content-Type: text/html; charset=UTF-8',
            '',
            '<p>Html</p>',
            '--pop3-boundary',
            'Content-Type: message/delivery-status',
            '',
            'Status',
            '--pop3-boundary',
            'Content-Type: application/pdf',
            'Content-Disposition: attachment; filename="report.pdf"',
            '',
            'PDF',
            '--pop3-boundary--',
            '',
        ]);
        $previousErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);

        try {
            $message = new StorageMessage(['raw' => $rawMessage]);
            $textBody = '';
            $htmlBody = '';
            $email = new Email();
            $args = [$message, &$textBody, &$htmlBody, $email];

            $this->callPrivateMethodOn($fetcher, 'extractParts', $args);
        } finally {
            error_reporting($previousErrorReporting);
        }

        self::assertStringContainsString('Plain', $textBody);
        self::assertStringContainsString('--- MESSAGE/DELIVERY-STATUS ---', $textBody);
        self::assertStringContainsString('Status', $textBody);
        self::assertStringContainsString('<p>Html</p>', $htmlBody);
        self::assertCount(1, $email->getEmailAttachments());
        self::assertCount(2, $persisted);
    }

    public function testDecodeHeaderValueAndLinkAddressHandlePlainAndExistingData(): void
    {
        $existing = (new Address())->setEmail('sender@example.com');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $addressRepository = $this->createStub(\Doctrine\ORM\EntityRepository::class);
        $addressRepository->method('findOneBy')->willReturn($existing);
        $entityManager->method('getRepository')->willReturn($addressRepository);
        $entityManager->expects(self::once())->method('persist');

        $fetcher = new Pop3Fetcher(
            $entityManager,
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $this->createStub(Pop3StorageFactory::class),
            $this->createStub(MailRoutingService::class),
            $this->createStub(ConnectorConfigRepository::class),
        );
        $email = new Email();

        self::assertSame('Plain text', $this->callPrivateMethodOn($fetcher, 'decodeHeaderValue', ['Plain text']));
        $this->callPrivateMethodOn($fetcher, 'linkAddress', [$email, '<sender@example.com>', 'Sender Name', EmailAddressType::SENDER]);

        self::assertSame('Sender Name', $existing->getName());
        self::assertCount(1, $email->getEmailAddresses());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function config(array $overrides = []): array
    {
        return array_merge([
            'pop3Host' => 'mail.example.com',
            'pop3Port' => 110,
            'pop3Username' => 'user',
            'pop3Password' => 'pass',
            'pop3Security' => 'ssl',
            'pop3DeleteAfterFetch' => true,
        ], $overrides);
    }

    private static function request(?int $limit = null, ?bool $deleteAfter = null, bool $oldestFirst = false): ConnectorFetchRequest
    {
        $request = new ConnectorFetchRequest();
        $request->limit = $limit;
        $request->deleteAfter = $deleteAfter;
        $request->oldestFirst = $oldestFirst;

        return $request;
    }

    private function callPrivateMethod(string $methodName, array $args): mixed
    {
        return $this->callPrivateMethodOn($this->fetcher, $methodName, $args);
    }

    private function callPrivateMethodOn(Pop3Fetcher $fetcher, string $methodName, array $args): mixed
    {
        $reflection = new \ReflectionClass(Pop3Fetcher::class);
        $method = $reflection->getMethod($methodName);

        return $method->invokeArgs($fetcher, $args);
    }

    private function createPartStub(string $content, string $contentType, string $transferEncoding = '', ?string $contentDisposition = null): StorageMessage
    {
        $part = $this->createStub(StorageMessage::class);
        $headers = $this->createStub(Headers::class);
        $part->method('getContent')->willReturn($content);
        $part->method('getHeaders')->willReturn($headers);
        $part->method('getHeader')->willReturnCallback(function (string $name) use ($contentType, $contentDisposition): ?HeaderInterface {
            $value = match ($name) {
                'content-type' => $contentType,
                'content-disposition' => $contentDisposition,
                default => null,
            };
            if (null === $value) {
                return null;
            }

            $header = $this->createStub(HeaderInterface::class);
            $header->method('getFieldValue')->willReturn($value);

            return $header;
        });
        $headers
            ->method('has')
            ->willReturnCallback(static fn (string $name): bool => match ($name) {
                'content-type' => true,
                'content-transfer-encoding' => '' !== $transferEncoding,
                'content-disposition' => null !== $contentDisposition,
                default => false,
            });
        $headers
            ->method('get')
            ->willReturnCallback(function (string $name) use ($contentType, $transferEncoding, $contentDisposition): ?HeaderInterface {
                $value = match ($name) {
                    'content-type' => $contentType,
                    'content-transfer-encoding' => $transferEncoding,
                    'content-disposition' => $contentDisposition,
                    default => null,
                };
                if (null === $value) {
                    return null;
                }

                $header = $this->createStub(HeaderInterface::class);
                $header->method('getFieldValue')->willReturn($value);

                return $header;
            });

        return $part;
    }

    private function createMessageStub(string $headerString, string $content, ?string $dateHeader = 'Tue, 02 Jan 2024 03:04:05 +0000'): StorageMessage
    {
        $message = $this->createStub(StorageMessage::class);
        $headers = $this->createStub(Headers::class);
        $message->method('getHeaders')->willReturn($headers);
        $message->method('getContent')->willReturn($content);
        $headers->method('toString')->willReturn($headerString);
        $headers->method('has')->willReturnCallback(static fn (string $name): bool => 'date' === $name && null !== $dateHeader);
        $headers->method('get')->willReturnCallback(function (string $name) use ($dateHeader): ?HeaderInterface {
            if ('date' !== $name || null === $dateHeader) {
                return null;
            }

            $header = $this->createStub(HeaderInterface::class);
            $header->method('getFieldValue')->willReturn($dateHeader);

            return $header;
        });

        return $message;
    }

    /**
     * @param array<string, string> $headerValues
     */
    private function createRichMessageStub(array $headerValues, string $content): StorageMessage
    {
        $message = $this->createStub(StorageMessage::class);
        $headers = $this->createStub(Headers::class);
        $message->method('getHeaders')->willReturn($headers);
        $message->method('getContent')->willReturn($content);
        $message->method('isMultipart')->willReturn(false);
        $headers->method('toString')->willReturn(implode("\n", array_map(
            static fn (string $name, string $value): string => sprintf('%s: %s', $name, $value),
            array_keys($headerValues),
            $headerValues,
        )));
        $headers
            ->method('has')
            ->willReturnCallback(static fn (string $name): bool => array_key_exists($name, $headerValues));
        $headers
            ->method('get')
            ->willReturnCallback(function (string $name) use ($headerValues): ?HeaderInterface {
                if (!array_key_exists($name, $headerValues)) {
                    return null;
                }

                $header = $this->createStub(HeaderInterface::class);
                $header->method('getFieldValue')->willReturn($headerValues[$name]);

                return $header;
            });

        return $message;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}

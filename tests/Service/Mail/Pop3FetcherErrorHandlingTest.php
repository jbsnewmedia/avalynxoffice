<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Mail;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\Pop3Fetcher;
use AvalynxOffice\Service\Mail\Pop3FetcherTestFunctionOverrides;
use AvalynxOffice\Service\Mail\MailRoutingService;
use AvalynxOffice\Service\Mail\Pop3StorageFactory;
use Doctrine\ORM\EntityManagerInterface;
use Laminas\Mail\Storage\Message as StorageMessage;
use Laminas\Mail\Storage\Pop3 as Pop3Storage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

final class Pop3FetcherErrorHandlingTest extends TestCase
{
    private string $tempDir;

    private string $cwd;

    private OutputInterface $output;

    private Pop3Fetcher $fetcher;

    protected function setUp(): void
    {
        Pop3FetcherTestFunctionOverrides::$forceInvalidMbConvertEncoding = false;
        Pop3FetcherTestFunctionOverrides::$forceMbDetectEncodingFailure = false;

        $this->tempDir = (getcwd() ?: sys_get_temp_dir()).'/pop3-error-handling-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->tempDir, 0777, true) || is_dir($this->tempDir));
        $this->cwd = getcwd() ?: sys_get_temp_dir();
        chdir($this->tempDir);

        $this->output = $this->createStub(OutputInterface::class);
        $this->fetcher = $this->createFetcher();
        $this->fetcher->setOutput($this->output);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->removeDirectory($this->tempDir);
        Pop3FetcherTestFunctionOverrides::$forceInvalidMbConvertEncoding = false;
        Pop3FetcherTestFunctionOverrides::$forceMbDetectEncodingFailure = false;
    }

    public function testSetErrorLogPathWritesSummaryAndDetailBlocks(): void
    {
        $errorLogPath = $this->tempDir.'/err.log';

        self::assertSame($this->fetcher, $this->fetcher->setErrorLogPath($errorLogPath));

        $this->callPrivate('logError', [
            1,
            'uid-1',
            'Betreff',
            new \RuntimeException('kaputt'),
            null,
            null,
        ]);

        $content = (string) file_get_contents($errorLogPath);
        self::assertStringContainsString('Error on message 1 (UIDL: uid-1)', $content);
        self::assertStringContainsString('=== ERROR ===', $content);
        self::assertStringContainsString('subject: Betreff', $content);
        self::assertStringContainsString('Trace:', $content);
    }

    public function testLogErrorDumpsRawMailViaProtocolFallback(): void
    {
        $this->fetcher->setErrorLogPath($this->tempDir.'/err.log');
        $connector = $this->createConnector();
        $storage = $this->createStorage();
        $storage->method('getRawHeader')->willThrowException(new \RuntimeException('no api'));
        $storage->method('getRawContent')->willThrowException(new \RuntimeException('no api'));
        $this->setProtocol($storage, new FakeProtocol("Subject: Test\r\n\r\nBody"));

        $this->callPrivate('logError', [3, 'uid-3', null, new \RuntimeException('kaputt'), $connector, $storage]);

        $dumpDir = $this->tempDir.'/mail_dumps/example.com/'.date('Y/m/d').'/error';
        $dumps = glob($dumpDir.'/*.eml') ?: [];
        self::assertCount(1, $dumps);
        self::assertStringContainsString('X-Mail-Fetch-Status: error', (string) file_get_contents($dumps[0]));
        self::assertStringContainsString('Subject: Test', (string) file_get_contents($dumps[0]));
    }

    public function testLogErrorReportsUnreadableRawAndSwallowsDumpFailures(): void
    {
        $errorLogPath = $this->tempDir.'/err.log';
        $this->fetcher->setErrorLogPath($errorLogPath);

        $storage = $this->createStorage();
        $storage->method('getRawHeader')->willThrowException(new \RuntimeException('no api'));
        $this->setProtocol($storage, new ThrowingProtocol());

        $connector = $this->createConnector();
        $this->callPrivate('logError', [4, null, null, new \RuntimeException('kaputt'), $connector, $storage]);

        $content = (string) file_get_contents($errorLogPath);
        self::assertStringContainsString('Could not read raw content of message 4', $content);
        self::assertStringContainsString('POP3 RETR fallback for message 4 failed', $content);

        $brokenConnector = $this->createStub(Connector::class);
        $brokenConnector->method('getDomain')->willThrowException(new \RuntimeException('connector kaputt'));
        $storageWithRaw = $this->createStorage();
        $storageWithRaw->method('getRawHeader')->willThrowException(new \RuntimeException('no api'));
        $this->setProtocol($storageWithRaw, new FakeProtocol("Subject: Test\r\n\r\nBody"));
        $this->callPrivate('logError', [4, null, null, new \RuntimeException('kaputt'), $brokenConnector, $storageWithRaw]);

        $content = (string) file_get_contents($errorLogPath);
        self::assertStringContainsString('Error dumping the failed mail', $content);
    }

    public function testFetchRawViaProtocolHandlesNonStringAndMissingProtocol(): void
    {
        $storage = $this->createStorage();
        $storage->method('getRawHeader')->willThrowException(new \RuntimeException('no api'));
        $this->setProtocol($storage, new FakeProtocol(123));

        self::assertSame('', $this->callPrivate('fetchRawViaProtocol', [$storage, 5]));

        $storageWithoutProtocol = $this->createStorage();
        $storageWithoutProtocol->method('getRawHeader')->willThrowException(new \RuntimeException('no api'));

        self::assertNull($this->callPrivate('fetchRawViaProtocol', [$storageWithoutProtocol, 5]));
    }

    public function testParseMessageWithSanitizedHeadersHandlesValidBrokenAndUnsalvageableInput(): void
    {
        $valid = $this->callPrivate('parseMessageWithSanitizedHeaders', ["Subject: OK\nFrom: a@example.com\n\nBody"]);
        self::assertInstanceOf(StorageMessage::class, $valid);
        self::assertSame('OK', $valid->getHeader('subject', 'string'));

        self::assertNull($this->callPrivate('parseMessageWithSanitizedHeaders', ["J\xfcrgen: kaputt"]));

        self::assertNull($this->callPrivate('parseMessageWithSanitizedHeaders', ["J\xfcrgen: kaputt\n\nBody"]));
    }

    public function testParseMessageWithSanitizedHeadersRepairsRawUtf8Headers(): void
    {
        $raw = "From: J\xfcrgen M\xfcller <j.mueller@example.com>\nSubject: Gr\xfc\xc3\x9fe\n\nBody";
        $parsed = $this->callPrivate('parseMessageWithSanitizedHeaders', [$raw]);

        self::assertInstanceOf(StorageMessage::class, $parsed);
        self::assertStringContainsString('j.mueller@example.com', $parsed->getHeader('from', 'string'));
    }

    public function testSanitizeRawHeadersEncodesUtf8AndKeepsContinuations(): void
    {
        $sanitized = $this->callPrivate('sanitizeRawHeaders', [
            "Subject: Hello\nSubject: Gr\xfc\xc3\x9fe\nFrom: J\xfcrgen M\xfcller <j.mueller@example.com>\nX-Test: a\n continuation\n\nX-After: b",
        ]);

        $lines = explode("\r\n", $sanitized);
        self::assertSame('Subject: Hello', $lines[0]);
        self::assertStringStartsWith('Subject:=?utf-8?B?', $lines[1]);
        self::assertStringContainsString('j.mueller@example.com', $lines[2]);
        self::assertSame(' continuation', $lines[4]);
        self::assertSame('', $lines[5]);
        self::assertSame('X-After: b', $lines[6]);
    }

    public function testEncodeUtf8As2047CoversAllConversionBranches(): void
    {
        self::assertSame('=?utf-8?B?'.base64_encode('Hello').'?=', $this->callPrivate('encodeUtf8As2047', ['Hello']));
        self::assertSame(
            '=?utf-8?B?'.base64_encode('Jürgen').'?=',
            $this->callPrivate('encodeUtf8As2047', [" J\xfcrgen"]),
        );

        Pop3FetcherTestFunctionOverrides::$forceMbDetectEncodingFailure = true;
        self::assertSame(
            '=?utf-8?B?'.base64_encode('Jrgen').'?=',
            $this->callPrivate('encodeUtf8As2047', [" J\xfcrgen"]),
        );

        Pop3FetcherTestFunctionOverrides::$forceMbDetectEncodingFailure = false;
        Pop3FetcherTestFunctionOverrides::$forceInvalidMbConvertEncoding = true;
        self::assertSame(
            '=?utf-8?B?'.base64_encode('Jrgen').'?=',
            $this->callPrivate('encodeUtf8As2047', [" J\xfcrgen"]),
        );
    }

    public function testExtractPartsSkipsBrokenPartsAndSurvivesIterationFailures(): void
    {
        $brokenPart = $this->createStub(StorageMessage::class);
        $brokenPart->method('getHeaders')->willThrowException(new \RuntimeException('part kaputt'));

        $multipart = $this->createStub(StorageMessage::class);
        $multipart->method('isMultipart')->willReturn(true);
        $multipart->method('hasChildren')->willReturn(false);
        $multipart->method('rewind');
        $multipart->method('valid')->willReturnOnConsecutiveCalls(true, true, false);
        $multipart->method('current')->willReturn($brokenPart);
        $multipart->method('key')->willReturn(0);

        $textBody = '';
        $htmlBody = '';
        $email = new \AvalynxOffice\Entity\Email();
        $this->callPrivate('extractParts', [$multipart, &$textBody, &$htmlBody, $email]);

        self::assertSame('', $textBody);

        $exploding = $this->createStub(StorageMessage::class);
        $exploding->method('isMultipart')->willReturn(true);
        $exploding->method('hasChildren')->willReturn(false);
        $exploding->method('rewind');
        $exploding->method('valid')->willReturn(true);
        $exploding->method('current')->willThrowException(new \RuntimeException('iteration kaputt'));
        $exploding->method('key')->willReturn(0);

        $this->callPrivate('extractParts', [$exploding, &$textBody, &$htmlBody, $email]);

        self::assertSame('', $textBody);
    }

    public function testDumpOriginalMessageWritesErrorStatusDump(): void
    {
        $connector = $this->createConnector();
        $message = $this->createStub(StorageMessage::class);
        $headers = $this->createStub(\Laminas\Mail\Headers::class);
        $headers->method('toString')->willReturn('Subject: Test');
        $message->method('getHeaders')->willReturn($headers);
        $message->method('getContent')->willReturn('Body');
        $message->method('isMultipart')->willReturn(false);

        $this->callPrivate('dumpOriginalMessage', [$message, $connector, null, 'account:uid-9', 'error', 'Header kaputt']);

        $dumpDir = $this->tempDir.'/mail_dumps/example.com/'.date('Y/m/d').'/error';
        $dumps = glob($dumpDir.'/*.eml') ?: [];
        self::assertCount(1, $dumps);
        $content = (string) file_get_contents($dumps[0]);
        self::assertStringContainsString('X-Mail-Fetch-Status: error', $content);
        self::assertStringContainsString('X-Mail-Fetch-Error: Header kaputt', $content);
        self::assertStringContainsString('Subject: Test', $content);
    }

    public function testFetchForConnectorSanitizesMessagesWithBrokenHeaders(): void
    {
        $account = (new \AvalynxOffice\Entity\Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $account->setInboxFolder((new \AvalynxOffice\Entity\Folder())->setName('Postfach'));
        $connector = $this->createConnector($account);

        $storage = $this->createStorage();
        $storage->method('countMessages')->willReturn(1);
        $storage->method('getUniqueId')->willReturn('uid-1');
        $storage->method('getMessage')->willThrowException(new \RuntimeException('Header kaputt'));
        $storage->method('getRawHeader')->willThrowException(new \RuntimeException('no api'));
        $storage->method('getRawContent')->willThrowException(new \RuntimeException('no api'));
        $this->setProtocol($storage, new FakeProtocol("Subject: Sanitized\nFrom: Sender <sender@example.com>\n\nBody"));

        $emailRepository = $this->createStub(EmailRepository::class);
        $emailRepository->method('findOneBy')->willReturn(null);

        $addressRepository = $this->createStub(\Doctrine\ORM\EntityRepository::class);
        $addressRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(\AvalynxOffice\Entity\Address::class)->willReturn($addressRepository);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $entityManager->method('isOpen')->willReturn(true);

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::exactly(2))->method('ensureSystemFolder');
        $folderManager->expects(self::once())->method('syncEmailSystemFolders');

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $fetcher = new Pop3Fetcher($entityManager, $emailRepository, $folderManager, $this->createStub(AttachmentStorage::class), $factory, $this->createRouting($account ?? null));
        $fetcher->setOutput($this->output);

        self::assertSame(['imported' => 1, 'skipped' => 0, 'errors' => 0], $fetcher->fetchForConnector($connector));
    }

    public function testFetchForConnectorReportsErrorAndClearsEntityManagerWhenSanitizationFails(): void
    {
        $account = (new \AvalynxOffice\Entity\Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $connector = $this->createConnector($account);
        $errorLogPath = $this->tempDir.'/err.log';

        $storage = $this->createStorage();
        $storage->method('countMessages')->willReturn(1);
        $storage->method('getUniqueId')->willReturn('uid-1');
        $storage->method('getMessage')->willThrowException(new \RuntimeException('Header kaputt'));
        $storage->method('getRawHeader')->willThrowException(new \RuntimeException('no api'));
        $storage->method('getRawContent')->willThrowException(new \RuntimeException('no api'));
        $this->setProtocol($storage, new FakeProtocol("J\xfcrgen: kaputt\n\nBody"));

        $emailRepository = $this->createStub(EmailRepository::class);
        $emailRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->expects(self::once())->method('clear');
        $entityManager->method('find')->willReturn(null);

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::never())->method('ensureSystemFolder');

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $fetcher = new Pop3Fetcher($entityManager, $emailRepository, $folderManager, $this->createStub(AttachmentStorage::class), $factory, $this->createRouting($account ?? null));
        $fetcher->setOutput($this->output);
        $fetcher->setErrorLogPath($errorLogPath);

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 1], $fetcher->fetchForConnector($connector));

        $content = (string) file_get_contents($errorLogPath);
        self::assertStringContainsString('Error on message 1', $content);
        self::assertStringContainsString('Header kaputt', $content);

        $dumpDir = $this->tempDir.'/mail_dumps/example.com/'.date('Y/m/d').'/error';
        self::assertNotEmpty(glob($dumpDir.'/*.eml'));
    }

    public function testFetchForConnectorReportsErrorWhenRawMailIsUnreadable(): void
    {
        $account = (new \AvalynxOffice\Entity\Account())
            ->setName('Support')
            ->setEmail('support@example.com');
        $connector = $this->createConnector($account);

        $storage = $this->createStorage();
        $storage->method('countMessages')->willReturn(1);
        $storage->method('getUniqueId')->willReturn('uid-1');
        $storage->method('getMessage')->willThrowException(new \RuntimeException('Header kaputt'));
        $storage->method('getRawHeader')->willThrowException(new \RuntimeException('no api'));
        $storage->method('getRawContent')->willThrowException(new \RuntimeException('no api'));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::never())->method('ensureSystemFolder');

        $factory = $this->createStub(Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $fetcher = new Pop3Fetcher($entityManager, $this->createStub(EmailRepository::class), $folderManager, $this->createStub(AttachmentStorage::class), $factory, $this->createRouting($account ?? null));
        $fetcher->setOutput($this->output);

        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 1], $fetcher->fetchForConnector($connector));
    }


    private function createRouting(?\AvalynxOffice\Entity\Account $account): MailRoutingService
    {
        $routing = $this->createStub(MailRoutingService::class);
        $routing->method('resolveTarget')->willReturnCallback(
            static fn (): ?array => null !== $account ? ['account' => $account, 'alias' => null] : null,
        );

        return $routing;
    }

    private function createConnector(?\AvalynxOffice\Entity\Account $account = null): Connector
    {
        $connector = (new Connector())
            ->setName('Support')
            ->setDomain('example.com')
            ->setPop3Host('mail.example.com')
            ->setPop3Port(110)
            ->setPop3Username('user')
            ->setPop3Password('secret');
        $connector->setDefaultAccount($account);

        return $connector;
    }

    private function createFetcher(): Pop3Fetcher
    {
        return new Pop3Fetcher(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $this->createStub(Pop3StorageFactory::class),
            $this->createStub(MailRoutingService::class),
        );
    }

    private function createStorage(): Pop3Storage
    {
        return $this->createStub(Pop3Storage::class);
    }

    private function setProtocol(Pop3Storage $storage, object $protocol): void
    {
        $property = new \ReflectionProperty(Pop3Storage::class, 'protocol');
        $property->setValue($storage, $protocol);
    }

    private function callPrivate(string $methodName, array $args): mixed
    {
        $method = new \ReflectionMethod(Pop3Fetcher::class, $methodName);

        return $method->invokeArgs($this->fetcher, $args);
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

final class FakeProtocol
{
    public function __construct(
        private readonly mixed $raw,
    ) {
    }

    public function retrieve(int $num): mixed
    {
        return \is_string($this->raw) ? str_replace('{num}', (string) $num, $this->raw) : $this->raw;
    }
}

final class ThrowingProtocol
{
    public function retrieve(int $num): string
    {
        throw new \RuntimeException('RETR kaputt');
    }
}

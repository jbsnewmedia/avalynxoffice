<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\Pop3ConnectorBundle\Service;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use JBSNewMedia\Pop3ConnectorBundle\Service\Pop3Fetcher;
use AvalynxOffice\Tests\Plugin\Pop3ConnectorBundle\Service\Pop3FetcherTestFunctionOverrides;
use AvalynxOffice\Service\Mail\MailRoutingService;
use JBSNewMedia\Pop3ConnectorBundle\Service\Pop3StorageFactory;
use Doctrine\ORM\EntityManagerInterface;
use Laminas\Mail\Header\HeaderInterface;
use Laminas\Mail\Headers;
use Laminas\Mail\Storage\Message as StorageMessage;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;
use PHPUnit\Framework\TestCase;

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

        $tempDir = (getcwd() ?: __DIR__).'/var/pop3-fetcher-helper-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tempDir, 0777, true) || is_dir($tempDir));
        $cwd = getcwd();

        try {
            chdir($tempDir);

            $this->callPrivateMethod('dumpOriginalMessage', [$message, 'example.com', null, 'account:uid-1', 'pending']);
            self::assertFileExists($tempDir.'/mail_dumps/example.com/2024/01/02/pending/account_uid-1.eml');
            self::assertInstanceOf(\DateTimeImmutable::class, $this->callPrivateMethod('extractDateFromMessage', [$message]));

            $this->callPrivateMethod('dumpOriginalMessage', [$message, 'example.com', $email, 'account:uid-1', 'success']);

            self::assertFileDoesNotExist($tempDir.'/mail_dumps/example.com/2024/01/02/pending/account_uid-1.eml');
            self::assertFileExists($tempDir.'/mail_dumps/example.com/2024/01/02/success/hash123.eml');
            self::assertSame('example.com', $this->callPrivateMethod('getConnectorDumpKey', [$connector, 'support']));

            $emailWithoutHash = (new Email())
                ->setId(Uuid::v7())
                ->setReceivedAt(new \DateTimeImmutable('2024-01-02 03:04:05'));
            $this->callPrivateMethod('dumpOriginalMessage', [$message, 'example.com', null, 'account:uid-2', 'pending']);
            $this->callPrivateMethod('dumpOriginalMessage', [$message, 'example.com', $emailWithoutHash, 'account:uid-2', 'success']);
            self::assertFileExists($tempDir.'/mail_dumps/example.com/2024/01/02/success/'.$emailWithoutHash->getId().'.eml');
            self::assertFileDoesNotExist($tempDir.'/mail_dumps/example.com/2024/01/02/pending/account_uid-2.eml');

            $invalidDateMessage = $this->createMessageStub("Date: invalid", 'body', 'invalid-date');
            self::assertNull($this->callPrivateMethod('extractDateFromMessage', [$invalidDateMessage]));

            $withoutDateMessage = $this->createMessageStub('', 'body', null);
            self::assertNull($this->callPrivateMethod('extractDateFromMessage', [$withoutDateMessage]));
        } finally {
            if (false !== $cwd) {
                chdir($cwd);
            }
            $this->removeDirectory($tempDir);
        }
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
        self::assertStringContainsString("<p>Html</p>", $htmlBody);
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
        $part->method('getHeader')->willReturnCallback(function (string $name) use ($contentType, $transferEncoding, $contentDisposition): ?HeaderInterface {
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

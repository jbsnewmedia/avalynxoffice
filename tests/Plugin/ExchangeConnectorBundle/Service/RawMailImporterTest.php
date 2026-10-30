<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Plugin\ExchangeConnectorBundle\Service;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailRoutingService;
use Doctrine\ORM\EntityManagerInterface;
use JBSNewMedia\ExchangeConnectorBundle\Service\RawMailImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RawMailImporterTest extends TestCase
{
    private string $dumpDir;

    protected function setUp(): void
    {
        $this->dumpDir = sys_get_temp_dir().'/avalynxoffice-raw-importer-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dumpDir);
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string}>
     */
    public static function attachmentFilenameProvider(): iterable
    {
        yield 'null falls back' => [null, 'attachment.bin'];
        yield 'empty falls back' => ['', 'attachment.bin'];
        yield 'dot dot falls back' => ['..', 'attachment.bin'];
        yield 'path traversal stripped' => ['../../etc/passwd', 'passwd'];
        yield 'backslash path stripped' => ['C:\\Users\\x\\evil.exe', 'evil.exe'];
        yield 'control chars removed' => ["inv\x00oice\r\n.pdf", 'invoice.pdf'];
        yield 'surrounding dots and spaces trimmed' => ['  .hidden. ', 'hidden'];
        yield 'plain name kept' => ['Rechnung 2024.pdf', 'Rechnung 2024.pdf'];
    }

    #[DataProvider('attachmentFilenameProvider')]
    public function testNormalizeAttachmentFilename(?string $input, string $expected): void
    {
        self::assertSame($expected, RawMailImporter::normalizeAttachmentFilename($input));
    }

    public function testNormalizeAttachmentFilenameLimitsLengthAndKeepsExtension(): void
    {
        $name = RawMailImporter::normalizeAttachmentFilename(str_repeat('a', 300).'.pdf');

        self::assertSame(255, strlen($name));
        self::assertStringEndsWith('.pdf', $name);
    }

    public function testDumpFileNameFiltersAndTruncates(): void
    {
        self::assertSame('AAMk_abc.1-2.eml', RawMailImporter::dumpFileName('AAMk/abc.1-2'));
        self::assertSame('_.._etc_passwd.eml', RawMailImporter::dumpFileName('../../etc/passwd'));
        self::assertSame('unknown.eml', RawMailImporter::dumpFileName('...'));

        $long = RawMailImporter::dumpFileName(str_repeat('x', 200));
        self::assertSame(str_repeat('x', 120).'.eml', $long);
    }

    public function testNormalizeDumpKeyNeverYieldsPathSegments(): void
    {
        self::assertSame('unknown_connector', RawMailImporter::normalizeDumpKey(''));
        self::assertSame('unknown_connector', RawMailImporter::normalizeDumpKey('.'));
        self::assertSame('unknown_connector', RawMailImporter::normalizeDumpKey('..'));
        self::assertSame('unknown_connector', RawMailImporter::normalizeDumpKey('...'));
        self::assertSame('example.com', RawMailImporter::normalizeDumpKey('.example.com.'));
        self::assertSame('_.._etc', RawMailImporter::normalizeDumpKey('../../etc'));
        self::assertSame('_.._escape', RawMailImporter::normalizeDumpKey('..\\..\\escape'));
        self::assertSame('user@example.com', RawMailImporter::normalizeDumpKey('user@example.com'));
        self::assertSame(str_repeat('x', 120), RawMailImporter::normalizeDumpKey(str_repeat('x', 300)));

        self::assertSame('unknown_connector', RawMailImporter::connectorDumpKey((new Connector())->setDomain('..')));
        self::assertSame('example.com', RawMailImporter::connectorDumpKey((new Connector())->setDomain('example.com')));
    }

    public function testTraversalDomainDumpsBelowFallbackDirectory(): void
    {
        $emailRepo = $this->createStub(EmailRepository::class);
        $emailRepo->method('findOneBy')->willReturn(null);
        $routing = $this->createStub(MailRoutingService::class);
        $routing->method('resolveTarget')->willReturn(null);

        $importer = new RawMailImporter(
            $this->createStub(EntityManagerInterface::class),
            $emailRepo,
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $routing,
            $this->dumpDir.'/mail_dumps',
        );

        $raw = "From: a@example.com\r\nTo: b@example.com\r\n\r\nBody\r\n";
        $importer->import((new Connector())->setDomain('..'), 'key', $raw);

        self::assertFileExists($this->dumpDir.'/mail_dumps/unknown_connector/'.date('Y/m/d').'/error/key.eml');
        self::assertFileDoesNotExist($this->dumpDir.'/'.date('Y/m/d').'/error/key.eml');
    }

    public function testPruningNeverLeavesTheDumpRoot(): void
    {
        $root = $this->dumpDir.'/mail_dumps';
        $outside = $this->dumpDir.'/outside/2000/01/01/error';
        mkdir($root, 0775, true);
        mkdir($outside, 0775, true);
        $outsideFile = $outside.'/old.eml';
        file_put_contents($outsideFile, 'old');
        touch($outsideFile, time() - 30 * 86400);
        $link = $root.'/linked';
        self::assertTrue(symlink($this->dumpDir.'/outside', $link));

        $importer = new RawMailImporter(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(EmailRepository::class),
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $this->createStub(MailRoutingService::class),
            $root,
            14,
        );

        $prune = new \ReflectionMethod(RawMailImporter::class, 'pruneOldDumps');
        try {
            $prune->invoke($importer, 'linked');
            self::assertFileExists($outsideFile);

            $prune->invoke($importer, '../outside');
            self::assertFileExists($outsideFile);
        } finally {
            unlink($link);
        }
    }

    public function testImportDumpsBelowConfiguredDirectoryWhenRoutingFails(): void
    {
        $emailRepo = $this->createStub(EmailRepository::class);
        $emailRepo->method('findOneBy')->willReturn(null);
        $routing = $this->createStub(MailRoutingService::class);
        $routing->method('resolveTarget')->willReturn(null);

        $importer = new RawMailImporter(
            $this->createStub(EntityManagerInterface::class),
            $emailRepo,
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $routing,
            $this->dumpDir,
        );

        $raw = "From: a@example.com\r\nTo: b@example.com\r\nSubject: Hi\r\n\r\nBody\r\n";
        $result = $importer->import((new Connector())->setDomain('example.com'), 'AAMk/key?1', $raw);

        self::assertSame('error', $result['status']);
        $expected = $this->dumpDir.'/example.com/'.date('Y/m/d').'/error/AAMk_key_1.eml';
        self::assertFileExists($expected);
        self::assertStringContainsString('X-Mail-Fetch-Status: error', (string) file_get_contents($expected));
        self::assertStringEndsWith($raw, (string) file_get_contents($expected));
    }

    public function testOldDumpsArePrunedOncePerRun(): void
    {
        $emailRepo = $this->createStub(EmailRepository::class);
        $emailRepo->method('findOneBy')->willReturn(null);
        $routing = $this->createStub(MailRoutingService::class);
        $routing->method('resolveTarget')->willReturn(null);

        $oldDir = $this->dumpDir.'/example.com/2000/01/01/error';
        mkdir($oldDir, 0775, true);
        $oldFile = $oldDir.'/old.eml';
        file_put_contents($oldFile, 'old');
        touch($oldFile, time() - 30 * 86400);

        $importer = new RawMailImporter(
            $this->createStub(EntityManagerInterface::class),
            $emailRepo,
            $this->createStub(FolderManager::class),
            $this->createStub(AttachmentStorage::class),
            $routing,
            $this->dumpDir,
            14,
        );
        $connector = (new Connector())->setDomain('example.com');
        $raw = "From: a@example.com\r\nTo: b@example.com\r\n\r\nBody\r\n";

        $importer->import($connector, 'first', $raw);
        self::assertFileDoesNotExist($oldFile);

        // Second call in the same run must not prune again (empty dirs were removed as well).
        mkdir($oldDir, 0775, true);
        file_put_contents($oldFile, 'old-again');
        touch($oldFile, time() - 30 * 86400);
        $importer->import($connector, 'second', $raw);
        self::assertFileExists($oldFile);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}

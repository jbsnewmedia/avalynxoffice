<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Attachment;
use App\Service\Mail\AttachmentStorage;
use PHPUnit\Framework\TestCase;

class AttachmentStorageTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir().'/mailer-attachment-storage-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            $this->removeDirectory($this->baseDir);
        }
    }

    public function testStoreWritesSegmentedFile(): void
    {
        $attachment = new Attachment();
        $attachment->setName('angebot.pdf');

        $storage = new AttachmentStorage('/tmp/project', $this->baseDir, 3, 3, 65_536);
        $payload = 'fixture-payload';

        $relativePath = $storage->store($attachment, $payload);
        $uuid = strtolower(str_replace('-', '', (string) $attachment->getId()));
        $expectedPath = substr($uuid, 0, 3).'/'.substr($uuid, 3, 3).'/'.substr($uuid, 6, 3).'/'.$uuid.'.pdf';

        $this->assertSame($expectedPath, $relativePath);
        $this->assertFileExists($this->baseDir.'/'.$relativePath);
        $this->assertSame($payload, file_get_contents($this->baseDir.'/'.$relativePath));
    }

    public function testStoreRejectsOversizedFiles(): void
    {
        $attachment = new Attachment();
        $attachment->setName('gross.zip');

        $storage = new AttachmentStorage('/tmp/project', $this->baseDir, 3, 3, 10);

        $this->expectException(\RuntimeException::class);
        $storage->store($attachment, str_repeat('a', 11));
    }

    public function testBaseDirResolveAndDownloadResponseUseStoredFile(): void
    {
        $attachment = (new Attachment())
            ->setName('angebot.tar.gz!')
            ->setMimeType('application/gzip');
        $storage = new AttachmentStorage('/tmp/project', 'attachments', 2, 2, 65_536);
        $payload = 'download-payload';

        $relativePath = $storage->store($attachment, $payload);
        $attachment->setFilePath($relativePath);

        $this->assertSame('/tmp/project/attachments', $storage->getBaseDir());
        $this->assertSame(65_536, $storage->getMaxBytes());
        $this->assertStringEndsWith('.gz', $relativePath);
        $this->assertSame('/tmp/project/attachments/'.$relativePath, $storage->resolveAbsolutePath($relativePath));
        $this->assertSame('/already/absolute/file.txt', $storage->resolveAbsolutePath('/already/absolute/file.txt'));

        $response = $storage->createDownloadResponse($attachment);

        $this->assertSame('application/gzip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $this->assertSame($payload, $output);
    }

    public function testBuildRelativePathStopsWhenSegmentOffsetExceedsUuid(): void
    {
        $attachment = (new Attachment())->setName('document.txt');
        $storage = new AttachmentStorage('/tmp/project', $this->baseDir, 20, 3, 65_536);

        $relativePath = $storage->store($attachment, 'payload');
        $uuid = strtolower(str_replace('-', '', (string) $attachment->getId()));

        $this->assertSame(substr($uuid, 0, 20).'/'.substr($uuid, 20, 20).'/'.$uuid.'.txt', $relativePath);
    }

    public function testResolveAbsolutePathRejectsBlankValue(): void
    {
        $storage = new AttachmentStorage('/tmp/project', $this->baseDir, 3, 3, 65_536);

        $this->expectException(\RuntimeException::class);
        $storage->resolveAbsolutePath('   ');
    }

    public function testCreateDownloadResponseRejectsMissingFile(): void
    {
        $attachment = (new Attachment())
            ->setName('missing.txt')
            ->setFilePath('missing.txt');
        $storage = new AttachmentStorage('/tmp/project', $this->baseDir, 3, 3, 65_536);

        $this->expectException(\RuntimeException::class);
        $storage->createDownloadResponse($attachment);
    }

    public function testGetBaseDirKeepsAbsoluteStorageDirectory(): void
    {
        $storage = new AttachmentStorage('/tmp/project', '/var/custom-attachments/', 3, 3, 65_536);

        $this->assertSame('/var/custom-attachments', $storage->getBaseDir());
    }

    public function testStoreRejectsAttachmentsWithoutIdentifier(): void
    {
        $attachment = new class extends Attachment {
            #[\Override]
            public function getId(): ?\Symfony\Component\Uid\UuidV7
            {
                return null;
            }
        };
        $attachment->setName('missing-id.txt');
        $storage = new AttachmentStorage('/tmp/project', $this->baseDir, 3, 3, 65_536);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Attachment-ID fehlt');
        $storage->store($attachment, 'payload');
    }

    public function testStoreFailsWhenAttachmentDirectoryCannotBeCreated(): void
    {
        $attachment = (new Attachment())->setName('broken.txt');
        $blockingPath = $this->baseDir.'-file';
        self::assertNotFalse(file_put_contents($blockingPath, 'block'));
        $storage = new AttachmentStorage('/tmp/project', $blockingPath, 3, 3, 65_536);

        try {
            set_error_handler(static fn (): bool => true);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('could not be written');
            $storage->store($attachment, 'payload');
        } finally {
            restore_error_handler();
            @unlink($blockingPath);
        }
    }

    private function removeDirectory(string $directory): void
    {
        $items = scandir($directory);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $directory.'/'.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}

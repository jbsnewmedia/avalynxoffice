<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Twig;

use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailAttachmentLink;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Twig\EmailInlineImagesExtension;
use PHPUnit\Framework\TestCase;

final class EmailInlineImagesExtensionTest extends TestCase
{
    private string $tempDir;

    private EmailInlineImagesExtension $extension;

    protected function setUp(): void
    {
        $this->tempDir = (getcwd() ?: sys_get_temp_dir()).'/inline-images-'.bin2hex(random_bytes(4));
        mkdir($this->tempDir.'/data', 0777, true);
        $this->extension = new EmailInlineImagesExtension(new AttachmentStorage($this->tempDir, 'data', 3, 3, 10 * 1024 * 1024));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testReplacesCidReferenceWithDataUri(): void
    {
        $html = '<html><body><img src="cid:sg24_logo"></body></html>';
        $email = $this->createEmailWithAttachment('sg24_logo', 'logo.png', 'image/png', 'PNGDATA');

        $result = $this->extension->inlineImages($html, $email);

        self::assertStringContainsString('src="data:image/png;base64,', $result);
        self::assertStringContainsString(base64_encode('PNGDATA'), $result);
        self::assertStringNotContainsString('cid:', $result);
    }

    public function testHtmlWithoutCidStaysUntouched(): void
    {
        $email = $this->createEmailWithAttachment('sg24_logo', 'logo.png', 'image/png', 'PNGDATA');

        $html = '<html><body><img src="https://example.com/banner.png"></body></html>';

        self::assertSame($html, $this->extension->inlineImages($html, $email));
    }

    public function testAttachmentWithoutContentIdIsIgnored(): void
    {
        $email = $this->createEmailWithAttachment(null, 'banner.png', 'image/png', 'PNGDATA');

        $html = '<html><body><img src="cid:sg24_logo"></body></html>';

        self::assertSame($html, $this->extension->inlineImages($html, $email));
    }

    public function testOversizedAttachmentStaysUntouched(): void
    {
        $email = $this->createEmailWithAttachment('big', 'big.png', 'image/png', str_repeat('X', 2 * 1024 * 1024));

        $html = '<html><body><img src="cid:big"></body></html>';

        self::assertSame($html, $this->extension->inlineImages($html, $email));
    }

    public function testMissingFileStaysUntouched(): void
    {
        $email = $this->createEmailWithAttachment('ghost', 'ghost.png', 'image/png', 'never stored');
        // Overwrite the file path with a location that does not exist.
        foreach ($email->getEmailAttachments() as $link) {
            $link->getAttachment()?->setFilePath('data/attachments/does/not/exist.bin');
        }

        $html = '<html><body><img src="cid:ghost"></body></html>';

        self::assertSame($html, $this->extension->inlineImages($html, $email));
    }

    public function testSanitizesBrokenMimeType(): void
    {
        $email = $this->createEmailWithAttachment('logo', 'logo.png', "image/png;\r\n name=\"logo.png\"", 'PNGDATA');

        $result = $this->extension->inlineImages('<img src="cid:logo">', $email);

        self::assertStringContainsString('data:image/png;base64,', $result);
        self::assertStringNotContainsString('name=', $result);
    }

    private function createEmailWithAttachment(?string $contentId, string $name, string $mimeType, string $data): Email
    {
        $connector = (new Connector())
            ->setName('Support')
            ->setDomain('example.com');
        $email = (new Email())
            ->setAccount($connector->getDefaultAccount())
            ->setSubject('Test');
        $email->setConnector($connector);

        $attachment = new Attachment();
        $attachment->setName($name);
        $attachment->setContentId($contentId);
        $attachment->setSize(strlen($data));
        $attachment->setMimeType($mimeType);
        $attachment->setSha1(sha1($data));
        $attachment->setFilePath($this->storeFile($data));

        $link = (new EmailAttachmentLink())
            ->setEmail($email)
            ->setAttachment($attachment);
        $email->addEmailAttachment($link);

        return $email;
    }

    private function storeFile(string $data): string
    {
        $storage = new AttachmentStorage($this->tempDir, 'data', 3, 3, 10 * 1024 * 1024);
        $attachment = new Attachment();
        $attachment->setName('stored.bin');

        return $storage->store($attachment, $data);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }

    public function testGetFiltersRegistersTheInlineImagesFilter(): void
    {
        $filters = $this->extension->getFilters();

        self::assertCount(1, $filters);
        self::assertSame('email_inline_images', $filters[0]->getName());
    }

    public function testLinkWithoutAttachmentIsSkipped(): void
    {
        $email = new Email();
        $email->addEmailAttachment((new EmailAttachmentLink())->setEmail($email));

        $html = '<html><body><img src="cid:orphan"></body></html>';

        self::assertSame($html, $this->extension->inlineImages($html, $email));
    }

    public function testAttachmentWithEmptyFilePathIsSkipped(): void
    {
        $email = $this->createEmailWithAttachment('logo', 'logo.png', 'image/png', 'PNGDATA');
        foreach ($email->getEmailAttachments() as $link) {
            $link->getAttachment()?->setFilePath('');
        }

        $html = '<html><body><img src="cid:logo"></body></html>';

        self::assertSame($html, $this->extension->inlineImages($html, $email));
    }

    public function testUnresolvableAttachmentPathIsSwallowed(): void
    {
        $storage = $this->createStub(AttachmentStorage::class);
        $storage->method('resolveAbsolutePath')->willThrowException(new \RuntimeException('storage kaputt'));
        $extension = new EmailInlineImagesExtension($storage);

        $email = $this->createEmailWithAttachment('logo', 'logo.png', 'image/png', 'PNGDATA');

        $html = '<html><body><img src="cid:logo"></body></html>';

        self::assertSame($html, $extension->inlineImages($html, $email));
    }

    public function testEmptyAttachmentFileIsSkipped(): void
    {
        $emptyPath = $this->tempDir.'/empty-attachment.bin';
        file_put_contents($emptyPath, '');

        $email = $this->createEmailWithAttachment('empty', 'empty.png', 'image/png', 'x');
        foreach ($email->getEmailAttachments() as $link) {
            $link->getAttachment()?->setFilePath($emptyPath);
        }

        $html = '<html><body><img src="cid:empty"></body></html>';

        self::assertSame($html, $this->extension->inlineImages($html, $email));
    }
}

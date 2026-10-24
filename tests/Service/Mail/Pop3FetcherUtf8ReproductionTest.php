<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Mail;

use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\Pop3Fetcher;
use AvalynxOffice\Service\Mail\MailRoutingService;
use AvalynxOffice\Service\Mail\Pop3StorageFactory;
use Doctrine\ORM\EntityManagerInterface;
use Laminas\Mail\Storage\Message as StorageMessage;
use PHPUnit\Framework\TestCase;

class Pop3FetcherUtf8ReproductionTest extends TestCase
{
    private $fetcher;

    protected function setUp(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $emailRepo = $this->createStub(\AvalynxOffice\Repository\EmailRepository::class);
        $folderManager = $this->createStub(\AvalynxOffice\Service\Mail\FolderManager::class);
        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $pop3StorageFactory = $this->createStub(Pop3StorageFactory::class);

        $this->fetcher = new Pop3Fetcher($em, $emailRepo, $folderManager, $attachmentStorage, $pop3StorageFactory, $this->createStub(MailRoutingService::class));
    }

    private function callPrivateMethod($methodName, array $args)
    {
        $reflection = new \ReflectionClass(Pop3Fetcher::class);
        $method = $reflection->getMethod($methodName);

        return $method->invokeArgs($this->fetcher, $args);
    }

    public function testToUtf8WithCorruptedInput(): void
    {
        // "Jürgen" in ISO-8859-1: J\xfcrgen
        $input = "J\xfcrgen Schwind";
        $result = $this->callPrivateMethod('toUtf8', [$input]);
        $this->assertEquals('Jürgen Schwind', $result);
    }

    public function testSafeHeaderWithMimeEncodedUmlaut(): void
    {
        // =?UTF-8?Q?J=C3=BCrgen_Schwind?=
        $msg = $this->createStub(StorageMessage::class);
        $headers = $this->createStub(\Laminas\Mail\Headers::class);
        $header = $this->createStub(\Laminas\Mail\Header\HeaderInterface::class);

        $headers->method('has')->willReturnCallback(static fn (string $name): bool => 'subject' === $name);
        $headers->method('get')->willReturnCallback(static fn (string $name): mixed => 'subject' === $name ? $header : null);
        $header->method('getFieldValue')->willReturn('=?UTF-8?Q?J=C3=BCrgen_Schwind?=');
        $msg->method('getHeaders')->willReturn($headers);

        $result = $this->callPrivateMethod('safeHeader', [$msg, 'subject']);
        $this->assertEquals('Jürgen Schwind', $result);
    }

    public function testParseAddressesWithEncodedName(): void
    {
        // Name in ISO-8859-1 encoded in MIME
        // "Jürgen Schwind" -> =?ISO-8859-1?Q?J=FCrgen_Schwind?=
        $headerValue = '=?ISO-8859-1?Q?J=FCrgen_Schwind?= <juergen@example.com>';
        $result = $this->callPrivateMethod('parseAddresses', [$headerValue]);

        $this->assertCount(1, $result);
        $this->assertEquals('Jürgen Schwind', $result[0]['name']);
        $this->assertEquals('juergen@example.com', $result[0]['email']);
    }

    public function testParseAddressesWithBenjaminSeelCase(): void
    {
        // The user reported: ???? Benjamin Seel, Gesch??ftsf??hrer bei Optics Seel
        // This looks like double encoding or wrong charset detection.
        // Let's test a string that has been wrongly interpreted before.

        $input = "Benjamin Seel, Gesch\xe4ftsf\xfchrer bei Optics Seel"; // ISO-8859-1
        $result = $this->callPrivateMethod('toUtf8', [$input]);
        $this->assertEquals('Benjamin Seel, Geschäftsführer bei Optics Seel', $result);
    }

    public function testParseAddressesWithDoubleEncodedInput(): void
    {
        // "Jürgen" -> UTF-8: J\xc3\xbcrgen
        // If this is treated as ISO-8859-1 and converted to UTF-8 again:
        $doubleEncoded = mb_convert_encoding("J\xc3\xbcrgen", 'UTF-8', 'ISO-8859-1');
        // Result is "JÃ¼rgen"

        $result = $this->callPrivateMethod('toUtf8', [$doubleEncoded]);
        $this->assertEquals('Jürgen', $result);
    }

    public function testParseAddressesWithBrokenMimeEncoding(): void
    {
        // Multi-line header with broken folding
        $headerValue = "=?UTF-8?Q?Benjamin_Seel?=\r\n , =?UTF-8?Q?Gesch=C3=A4ftsf=C3=BChrer?=";
        $msg = $this->createStub(StorageMessage::class);
        $headers = $this->createStub(\Laminas\Mail\Headers::class);
        $header = $this->createStub(\Laminas\Mail\Header\HeaderInterface::class);
        $headers->method('has')->willReturn(true);
        $headers->method('get')->willReturn($header);
        $header->method('getFieldValue')->willReturn($headerValue);
        $msg->method('getHeaders')->willReturn($headers);

        $result = $this->callPrivateMethod('safeHeader', [$msg, 'subject']);
        $this->assertEquals('Benjamin Seel, Geschäftsführer', $result);
    }

    public function testAdjacentMimeEncodedSegments(): void
    {
        // mb_decode_mimeheader often leaves the space between segments if not handled correctly
        $headerValue = '=?UTF-8?Q?J=C3=BCrgen?= =?UTF-8?Q?_Schwind?=';
        $msg = $this->createStub(StorageMessage::class);
        $headers = $this->createStub(\Laminas\Mail\Headers::class);
        $header = $this->createStub(\Laminas\Mail\Header\HeaderInterface::class);
        $headers->method('has')->willReturn(true);
        $headers->method('get')->willReturn($header);
        $header->method('getFieldValue')->willReturn($headerValue);
        $msg->method('getHeaders')->willReturn($headers);

        $result = $this->callPrivateMethod('safeHeader', [$msg, 'subject']);
        $this->assertEquals('Jürgen Schwind', $result);
    }
}

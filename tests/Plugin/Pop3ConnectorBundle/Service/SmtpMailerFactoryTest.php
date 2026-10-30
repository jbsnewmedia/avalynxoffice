<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Plugin\Pop3ConnectorBundle\Service;

use JBSNewMedia\Pop3ConnectorBundle\Service\SmtpMailerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

final class SmtpMailerFactoryTest extends TestCase
{
    public function testCreatesMailerForPlainSmtpConfig(): void
    {
        $mailer = (new SmtpMailerFactory())->fromConfig($this->config([
            'smtpUsername' => 'user@example.com',
            'smtpPassword' => 'secret pass',
            'smtpSecurity' => 'tls',
        ]));

        self::assertInstanceOf(MailerInterface::class, $mailer);
        $transport = $this->transportOf($mailer);
        self::assertSame('user@example.com', $transport->getUsername());
        self::assertSame('secret pass', $transport->getPassword());
    }

    public function testCreatesMailerForSslConfigWithDefaultPort(): void
    {
        $mailer = (new SmtpMailerFactory())->fromConfig($this->config(['smtpSecurity' => 'ssl']));

        self::assertInstanceOf(MailerInterface::class, $mailer);
        $stream = $this->transportOf($mailer)->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertTrue($stream->isTLS());
    }

    public function testStartTlsIsEnforcedViaRequireTls(): void
    {
        $transport = $this->transportOf((new SmtpMailerFactory())->fromConfig($this->config([
            'smtpSecurity' => 'tls',
            'smtpPort' => 587,
        ])));

        self::assertTrue($transport->isTlsRequired());
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertFalse($stream->isTLS());
        self::assertSame(587, $stream->getPort());
    }

    public function testNoSecurityDoesNotRequireTls(): void
    {
        $transport = $this->transportOf((new SmtpMailerFactory())->fromConfig($this->config(['smtpSecurity' => ''])));

        self::assertFalse($transport->isTlsRequired());
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertFalse($stream->isTLS());
        self::assertSame(25, $stream->getPort());
    }

    public function testCredentialsWithReservedCharactersAreNotUrlEncoded(): void
    {
        $transport = $this->transportOf((new SmtpMailerFactory())->fromConfig($this->config([
            'smtpUsername' => 'us:er@example.com',
            'smtpPassword' => 'p@ss/w?rd#1 &x',
        ])));

        self::assertSame('us:er@example.com', $transport->getUsername());
        self::assertSame('p@ss/w?rd#1 &x', $transport->getPassword());
    }

    #[DataProvider('invalidHosts')]
    public function testInvalidHostIsRejected(string $host): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SmtpMailerFactory())->fromConfig($this->config(['smtpHost' => $host]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHosts(): iterable
    {
        yield 'empty' => [''];
        yield 'path' => ['mail.example.com/foo'];
        yield 'query' => ['mail.example.com?verify_peer=0'];
        yield 'fragment' => ['mail.example.com#x'];
        yield 'userinfo' => ['evil@mail.example.com'];
        yield 'whitespace' => ['mail example.com'];
        yield 'injected port' => ['mail.example.com:25?x=1'];
        yield 'backslash' => ['mail\\example.com'];
    }

    #[DataProvider('validHosts')]
    public function testValidHostIsAccepted(string $host): void
    {
        self::assertSame($host, SmtpMailerFactory::validateHost($host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validHosts(): iterable
    {
        yield 'hostname' => ['mail.example.com'];
        yield 'single label' => ['localhost'];
        yield 'ipv4' => ['192.168.1.10'];
        yield 'ipv6' => ['::1'];
        yield 'bracketed ipv6' => ['[2001:db8::1]'];
    }

    #[DataProvider('invalidPorts')]
    public function testInvalidPortIsRejected(int $port): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SmtpMailerFactory())->fromConfig($this->config(['smtpPort' => $port]));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidPorts(): iterable
    {
        yield 'negative' => [-1];
        yield 'too large' => [65536];
    }

    private function transportOf(MailerInterface $mailer): EsmtpTransport
    {
        self::assertInstanceOf(Mailer::class, $mailer);
        $property = new \ReflectionProperty(Mailer::class, 'transport');
        $transport = $property->getValue($mailer);
        self::assertInstanceOf(EsmtpTransport::class, $transport);

        return $transport;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'smtpHost' => 'mail.example.com',
            'smtpPort' => null,
            'smtpUsername' => null,
            'smtpPassword' => null,
            'smtpSecurity' => null,
        ], $overrides);
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use AvalynxOffice\Service\ConnectorPluginRegistry;
use AvalynxOffice\Tests\Support\FakeConnectorPlugin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\NullTransport;

final class ConnectorPluginRegistryTest extends TestCase
{
    public function testCollectsAndSortsPluginsByKey(): void
    {
        $smtp = new FakeConnectorPlugin('smtp');
        $pop3 = new FakeConnectorPlugin('pop3');

        $registry = $this->registry($smtp, $pop3);

        self::assertSame(['pop3', 'smtp'], array_map(static fn ($plugin): string => $plugin->getKey(), $registry->all()));
        self::assertSame($pop3, $registry->get('pop3'));
        self::assertNull($registry->get('unknown'));
    }

    public function testInputAndOutputStateIsAggregatedAcrossPlugins(): void
    {
        $connector = new Connector();
        $inputOnly = new FakeConnectorPlugin('pop3', input: true, output: false, inputConfigured: true);
        $outputOnly = new FakeConnectorPlugin('smtp', input: false, output: true, outputConfigured: false);

        $registry = $this->registry($inputOnly, $outputOnly);

        self::assertTrue($registry->isInputConfigured($connector));
        self::assertFalse($registry->isOutputConfigured($connector));

        $outputOnly->outputConfigured = true;

        self::assertTrue($registry->isOutputConfigured($connector));
    }

    public function testStatusMergesFirstNonNullValuesPerModule(): void
    {
        $connector = new Connector();
        $first = new class('first') extends FakeConnectorPlugin {
            #[\Override]
            public function getStatus(Connector $connector, array $config): array
            {
                return ['mails' => '1 von 2', 'contacts' => null, 'calendar' => '3 von 3'];
            }
        };
        $second = new class('second') extends FakeConnectorPlugin {
            #[\Override]
            public function getStatus(Connector $connector, array $config): array
            {
                return ['mails' => '9 von 9', 'contacts' => '4 von 4', 'calendar' => null];
            }
        };

        $registry = $this->registry($first, $second);

        self::assertSame(['mails' => '1 von 2', 'contacts' => '4 von 4', 'calendar' => '3 von 3'], $registry->getStatus($connector));
    }

    public function testCreateMailerReturnsMailerOfFirstConfiguredOutputPlugin(): void
    {
        $connector = new Connector();
        $mailer = new Mailer(new NullTransport());
        $unconfigured = new FakeConnectorPlugin('smtp-a', input: false, output: true, outputConfigured: false, mailer: $mailer);
        $configured = new FakeConnectorPlugin('smtp-b', input: false, output: true, outputConfigured: true, mailer: $mailer);

        self::assertNull($this->registry($unconfigured)->createMailer($connector));

        $registry = $this->registry($unconfigured, $configured);
        self::assertSame($mailer, $registry->createMailer($connector));
        self::assertSame([$connector], $configured->mailerCalls);
    }

    public function testCreateMailerReportsPluginWithoutMailerInstance(): void
    {
        $broken = new FakeConnectorPlugin('smtp', input: false, output: true, outputConfigured: true, mailer: null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No mailer configured on the fake plugin.');

        $this->registry($broken)->createMailer(new Connector());
    }

    public function testGetFetchersReturnsOnlyConfiguredInputPlugins(): void
    {
        $connector = new Connector();
        $unconfigured = new FakeConnectorPlugin('pop3', inputConfigured: false);
        $configured = new FakeConnectorPlugin('other', inputConfigured: true);
        $configured->inputConfigured = true;

        $registry = $this->registry($unconfigured, $configured);
        $fetchers = $registry->getFetchers($connector);

        self::assertCount(1, $fetchers);
        self::assertSame('other', $fetchers[0]['plugin']->getKey());
        self::assertSame([], $fetchers[0]['config']);
    }

    public function testGetConfigReadsStoredConfigFromRepository(): void
    {
        $repository = $this->createMock(ConnectorConfigRepository::class);
        $repository->expects(self::once())
            ->method('getConfig')
            ->with(self::callback(static fn (object $connector): bool => $connector instanceof Connector), 'pop3')
            ->willReturn(['pop3Host' => 'mail.example.com']);

        $registry = new ConnectorPluginRegistry([new FakeConnectorPlugin('pop3')], $repository);

        self::assertSame(['pop3Host' => 'mail.example.com'], $registry->getConfig(new Connector(), $registry->get('pop3')));
    }

    private function registry(FakeConnectorPlugin ...$plugins): ConnectorPluginRegistry
    {
        return new ConnectorPluginRegistry($plugins, $this->createStub(ConnectorConfigRepository::class));
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\Pop3ConnectorBundle\Plugin;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use AvalynxOffice\Repository\EmailRepository;
use Doctrine\ORM\EntityManagerInterface;
use JBSNewMedia\Pop3ConnectorBundle\Form\Pop3ConnectorSettingsType;
use JBSNewMedia\Pop3ConnectorBundle\Plugin\Pop3ConnectorPlugin;
use JBSNewMedia\Pop3ConnectorBundle\Service\Pop3Fetcher;
use JBSNewMedia\Pop3ConnectorBundle\Service\SmtpMailerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Contracts\Translation\TranslatorInterface;

final class Pop3ConnectorPluginTest extends TestCase
{
    public function testPluginDescribesItselfAndChecksConfigurationState(): void
    {
        $plugin = $this->createPlugin();

        self::assertSame('pop3', $plugin->getKey());
        self::assertSame('connector.plugin.pop3', $plugin->getName());
        self::assertTrue($plugin->supportsInput());
        self::assertTrue($plugin->supportsOutput());
        self::assertSame(Pop3ConnectorSettingsType::class, $plugin->getSettingsFormType());
        self::assertSame('@Pop3Connector/pop3_connector/settings_form.html.twig', $plugin->getSettingsTemplate());
        self::assertSame(['pop3Password', 'smtpPassword'], $plugin->getSecretConfigKeys());

        self::assertFalse($plugin->isInputConfigured([]));
        self::assertFalse($plugin->isInputConfigured(['pop3Host' => 'mail.example.com']));
        self::assertFalse($plugin->isInputConfigured(['pop3Host' => 'mail.example.com', 'pop3Username' => 'user']));
        self::assertTrue($plugin->isInputConfigured(['pop3Host' => 'mail.example.com', 'pop3Username' => 'user', 'pop3Password' => 'secret']));
        self::assertFalse($plugin->isOutputConfigured([]));
        self::assertTrue($plugin->isOutputConfigured(['smtpHost' => 'mail.example.com']));
    }

    public function testStatusShowsLocalOfTotalMailsAndNoContactCalendarStatus(): void
    {
        $connector = new Connector();
        $emailRepository = $this->createMock(EmailRepository::class);
        $emailRepository->expects(self::atLeastOnce())->method('count')->with(['connector' => $connector])->willReturn(120);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('connector.status.of', ['%local%' => 120, '%total%' => 251])
            ->willReturn('120 von 251');

        $plugin = $this->createPlugin($emailRepository, $translator);

        self::assertSame(
            ['mails' => '120 von 251', 'contacts' => null, 'calendar' => null],
            $plugin->getStatus($connector, ['server_total' => 251])
        );

        // Without a known server count there is no mail status.
        self::assertSame(
            ['mails' => null, 'contacts' => null, 'calendar' => null],
            $plugin->getStatus($connector, [])
        );
    }

    public function testFetchDelegatesToTheFetcherAndMailerToTheFactory(): void
    {
        $connector = new Connector();
        $config = ['pop3Host' => 'mail.example.com'];
        $request = new ConnectorFetchRequest();
        $fetcher = $this->createMock(Pop3Fetcher::class);
        $fetcher->expects(self::once())
            ->method('fetch')
            ->with($connector, $config, $request)
            ->willReturn(['imported' => 5, 'skipped' => 0, 'errors' => 0]);

        $mailer = new Mailer(new NullTransport());
        $mailerFactory = $this->createMock(SmtpMailerFactory::class);
        $mailerFactory->expects(self::once())
            ->method('fromConfig')
            ->with($config)
            ->willReturn($mailer);

        $plugin = $this->createPlugin(null, null, $fetcher, $mailerFactory);

        self::assertSame(['imported' => 5, 'skipped' => 0, 'errors' => 0], $plugin->fetch($connector, $config, $request));
        self::assertInstanceOf(MailerInterface::class, $plugin->createMailer($connector, $config));
    }

    private function createPlugin(?EmailRepository $emailRepository = null, ?TranslatorInterface $translator = null, ?Pop3Fetcher $fetcher = null, ?SmtpMailerFactory $mailerFactory = null): Pop3ConnectorPlugin
    {
        $emailRepository ??= $this->createStub(EmailRepository::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $class): object => Email::class === $class ? $emailRepository : new \stdClass()
        );

        return new Pop3ConnectorPlugin(
            $entityManager,
            $translator ?? $this->createStub(TranslatorInterface::class),
            $fetcher ?? $this->createStub(Pop3Fetcher::class),
            $mailerFactory ?? $this->createStub(SmtpMailerFactory::class),
        );
    }
}

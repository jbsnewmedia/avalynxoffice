<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Mail;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAlias;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailRoutingService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailRoutingServiceTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $em;

    private MailRoutingService $service;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
        $this->service = new MailRoutingService(
            $this->em,
            $this->em->getRepository(\AvalynxOffice\Entity\AccountAlias::class),
            $this->em->getRepository(\AvalynxOffice\Entity\Connector::class),
            static::getContainer()->get(FolderManager::class),
        );
    }

    private function seedConnectorWithAccounts(): array
    {
        $info = (new Account())->setName('Info')->setEmail('info@example.com');
        $support = (new Account())->setName('Support')->setEmail('support@example.com');
        $this->em->persist($info);
        $this->em->persist($support);

        $connector = (new Connector())
            ->setName('Example')
            ->setDomain('example.com')
            ->setDefaultAccount($support);
        $this->em->persist($connector);

        $alias = (new AccountAlias())
            ->setAccount($info)
            ->setConnector($connector)
            ->setLocalPart('info');
        $this->em->persist($alias);
        $info->addAlias($alias);
        $connector->addAlias($alias);

        $this->em->flush();

        return [$connector, $info, $support, $alias];
    }

    public function testResolveTargetPrefersExactAliasMatchesInHeaderOrder(): void
    {
        [$connector, $info] = $this->seedConnectorWithAccounts();

        $target = $this->service->resolveTarget($connector, ['someone@other.example', 'INFO@example.com']);

        self::assertNotNull($target);
        self::assertSame($info, $target['account']);
        self::assertSame('info', $target['alias']?->getLocalPart());
    }

    public function testResolveTargetFallsBackToDefaultAccount(): void
    {
        [$connector, , $support] = $this->seedConnectorWithAccounts();

        $target = $this->service->resolveTarget($connector, ['stranger@example.com']);

        self::assertNotNull($target);
        self::assertSame($support, $target['account']);
        self::assertNull($target['alias']);
    }

    public function testResolveTargetReturnsNullWithoutDefaultAccountAndNoMatch(): void
    {
        [$connector] = $this->seedConnectorWithAccounts();
        $connector->setDefaultAccount(null);
        $this->em->flush();

        self::assertNull($this->service->resolveTarget($connector, ['stranger@example.com']));
    }

    public function testRerouteLocalPartMovesMessageStockToTargetAccount(): void
    {
        [$connector, $info, $support] = $this->seedConnectorWithAccounts();

        $oldInbox = $this->ensureInbox($support);
        $newInbox = $this->ensureInbox($info);

        $email = (new Email())
            ->setSubject('Stock mail')
            ->setReceivedAt(new \DateTimeImmutable('-1 day'))
            ->setAccount($support)
            ->setConnector($connector)
            ->setMatchedLocalPart('info');
        $this->em->persist($email);
        $emailFolderLink = (new EmailFolder())->setEmail($email)->setFolder($oldInbox);
        $email->addEmailFolder($emailFolderLink);
        $this->em->persist($emailFolderLink);
        $this->em->flush();

        $moved = $this->service->rerouteLocalPart($connector, 'info', $info);

        self::assertSame(1, $moved);
        $this->em->refresh($email);
        self::assertSame($info, $email->getAccount());
        $folders = $email->getEmailFolders()->map(static fn (EmailFolder $link): ?Folder => $link->getFolder())->toArray();
        self::assertContains($newInbox, $folders);
        self::assertNotContains($oldInbox, $folders);

        // Same target again: nothing moves.
        self::assertSame(0, $this->service->rerouteLocalPart($connector, 'info', $info));
    }

    public function testRerouteDefaultAccountMovesUnmatchedMessages(): void
    {
        [$connector, , $support] = $this->seedConnectorWithAccounts();

        $target = (new Account())->setName('Catchall')->setEmail('catchall@example.com');
        $this->em->persist($target);
        $targetInbox = $this->ensureInbox($target);

        $email = (new Email())
            ->setSubject('Default delivered')
            ->setReceivedAt(new \DateTimeImmutable('-1 day'))
            ->setAccount($support)
            ->setConnector($connector);
        $this->em->persist($email);
        $this->em->flush();

        self::assertSame(0, $this->service->rerouteDefaultAccount($connector, null, $target));
        self::assertSame(0, $this->service->rerouteDefaultAccount($connector, $support, $support));
        self::assertSame(1, $this->service->rerouteDefaultAccount($connector, $support, $target));

        $this->em->refresh($email);
        self::assertSame($target, $email->getAccount());
        $folders = $email->getEmailFolders()->map(static fn (EmailFolder $link): ?Folder => $link->getFolder())->toArray();
        self::assertContains($targetInbox, $folders);
    }

    public function testConnectorForDomainIsCaseInsensitive(): void
    {
        $this->seedConnectorWithAccounts();

        self::assertNotNull($this->service->connectorForDomain('Example.COM'));
        self::assertNull($this->service->connectorForDomain('unknown.example'));
    }

    public function testStaticAddressHelpersHandleMalformedInput(): void
    {
        self::assertSame('info', MailRoutingService::localPartOf('Info@Example.com'));
        self::assertNull(MailRoutingService::localPartOf('no-at-sign'));
        self::assertNull(MailRoutingService::localPartOf('@example.com'));
        self::assertNull(MailRoutingService::localPartOf('user@'));
        self::assertNull(MailRoutingService::localPartOf('@'));
        self::assertSame('example.com', MailRoutingService::domainOf('user@Example.com'));
        self::assertNull(MailRoutingService::domainOf('no-at-sign'));
        self::assertNull(MailRoutingService::domainOf('user@'));
    }

    private function ensureInbox(Account $account): Folder
    {
        $folderManager = static::getContainer()->get(FolderManager::class);
        $folderManager->ensureSystemFolder($account, FolderFunction::MAILBOX);
        $folder = $folderManager->ensureSystemFolder($account, FolderFunction::INBOX);
        $this->em->flush();

        return $folder;
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Repository;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The import key diff source of the connector fetchers: connectors diff
 * the messages on the server against this set, so already imported
 * messages never occupy the fetch window.
 */
#[Group('integration')]
final class EmailRepositoryTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $em;

    private EmailRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(EmailRepository::class);
        $this->refreshDatabase($this->em);
    }

    public function testImportKeysForConnectorReturnsOnlyTheConnectorsKeys(): void
    {
        $connectorA = (new Connector())
            ->setName('Connector A')
            ->setDomain('a.example.com');
        $connectorB = (new Connector())
            ->setName('Connector B')
            ->setDomain('b.example.com');
        $this->em->persist($connectorA);
        $this->em->persist($connectorB);

        $account = $connectorA->getDefaultAccount();

        $emailA1 = (new Email())
            ->setAccount($account)
            ->setSubject('A1')
            ->setImportKey($connectorA->getId()->toRfc4122().':uidl-1');
        $emailA1->setConnector($connectorA);
        $emailA2 = (new Email())
            ->setAccount($account)
            ->setSubject('A2')
            ->setImportKey($connectorA->getId()->toRfc4122().':uidl-2');
        $emailA2->setConnector($connectorA);
        $emailB1 = (new Email())
            ->setAccount($account)
            ->setSubject('B1')
            ->setImportKey($connectorB->getId()->toRfc4122().':uidl-1');
        $emailB1->setConnector($connectorB);
        $noKey = (new Email())
            ->setAccount($account)
            ->setSubject('NoKey');
        $noKey->setConnector($connectorA);

        $this->em->persist($emailA1);
        $this->em->persist($emailA2);
        $this->em->persist($emailB1);
        $this->em->persist($noKey);
        $this->em->flush();

        $keys = $this->repository->importKeysForConnector($connectorA);

        self::assertSame([
            $connectorA->getId()->toRfc4122().':uidl-1' => true,
            $connectorA->getId()->toRfc4122().':uidl-2' => true,
        ], $keys);
    }

    public function testImportKeysForConnectorReturnsEmptySetWithoutEmails(): void
    {
        $connector = (new Connector())
            ->setName('Empty Connector')
            ->setDomain('empty.example.com');
        $this->em->persist($connector);
        $this->em->flush();

        self::assertSame([], $this->repository->importKeysForConnector($connector));
    }

    /**
     * Three emails across two folders. "Second" sits in both folders.
     *
     * @return array{Account, Folder, Folder, array<Email>}
     */
    private function seedFolderMailSet(): array
    {
        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');

        $inbox = (new Folder())
            ->setName('Inbox')
            ->setFunctions(['inbox']);
        $archive = (new Folder())
            ->setName('Archive');
        $account->addFolder($inbox);
        $account->addFolder($archive);

        $first = (new Email())
            ->setAccount($account)
            ->setSubject('First')
            ->setSentAt(new \DateTimeImmutable('2026-02-01 10:00:00'));
        $second = (new Email())
            ->setAccount($account)
            ->setSubject('Second')
            ->setReceivedAt(new \DateTimeImmutable('2026-02-02 10:00:00'));
        $third = (new Email())
            ->setAccount($account)
            ->setSubject('Third')
            ->setSentAt(new \DateTimeImmutable('2026-02-03 10:00:00'));

        $this->linkEmail($first, $inbox);
        $this->linkEmail($second, $inbox);
        $this->linkEmail($second, $archive);
        $this->linkEmail($third, $archive);

        $this->em->persist($account);
        $this->em->persist($inbox);
        $this->em->persist($archive);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->persist($third);
        $this->em->flush();

        return [$account, $inbox, $archive, [$first, $second, $third]];
    }

    private function linkEmail(Email $email, Folder $folder): void
    {
        $link = (new EmailFolder())
            ->setEmail($email)
            ->setFolder($folder);
        $email->addEmailFolder($link);
        $this->em->persist($link);
    }

    public function testCountInFoldersCountsEachEmailOnceAndReturnsZeroWithoutFolders(): void
    {
        [, $inbox, $archive] = $this->seedFolderMailSet();

        self::assertSame(3, $this->repository->countInFolders([$inbox, $archive]));
        self::assertSame(2, $this->repository->countInFolders([$inbox]));
        self::assertSame(0, $this->repository->countInFolders([]));
    }

    public function testFindInFoldersPaginatedSortsByBestDateAndFetchesTheAccount(): void
    {
        [$account, $inbox, $archive] = $this->seedFolderMailSet();

        $emails = $this->repository->findInFoldersPaginated([$inbox, $archive], 10, 0);

        self::assertSame(['Third', 'Second', 'First'], array_map(static fn (Email $email) => $email->getSubject(), $emails));
        foreach ($emails as $email) {
            self::assertSame($account->getName(), $email->getAccount()->getName());
        }
    }

    public function testFindInFoldersPaginatedShowsEmailsLinkedToSeveralFoldersOnlyOnce(): void
    {
        [, $inbox, $archive] = $this->seedFolderMailSet();

        $emails = $this->repository->findInFoldersPaginated([$inbox, $archive], 10, 0);
        $subjects = array_map(static fn (Email $email) => $email->getSubject(), $emails);

        self::assertCount(1, array_keys($subjects, 'Second', true));
    }

    public function testFindInFoldersPaginatedReturnsTheRequestedPage(): void
    {
        [, $inbox, $archive] = $this->seedFolderMailSet();

        $page = $this->repository->findInFoldersPaginated([$inbox, $archive], 2, 0);
        self::assertSame(['Third', 'Second'], array_map(static fn (Email $email) => $email->getSubject(), $page));

        $secondPage = $this->repository->findInFoldersPaginated([$inbox, $archive], 2, 2);
        self::assertSame(['First'], array_map(static fn (Email $email) => $email->getSubject(), $secondPage));

        self::assertSame([], $this->repository->findInFoldersPaginated([], 10, 0));
    }

    public function testUnreadCountsPerFolderCountsUnreadEmailsPerFolder(): void
    {
        [, $inbox, $archive, $emails] = $this->seedFolderMailSet();

        $emails[0]->setIsRead(true);
        $this->em->flush();

        self::assertSame([
            (string) $inbox->getId() => 1,
            (string) $archive->getId() => 2,
        ], $this->repository->unreadCountsPerFolder([$inbox, $archive]));
        self::assertSame([], $this->repository->unreadCountsPerFolder([]));
    }

    public function testRankInFoldersReturnsThePositionWithinTheFolderSet(): void
    {
        [, $inbox, $archive, $emails] = $this->seedFolderMailSet();

        self::assertSame(2, $this->repository->rankInFolders($emails[0], [$inbox, $archive]));
        self::assertSame(1, $this->repository->rankInFolders($emails[1], [$inbox, $archive]));
        self::assertSame(0, $this->repository->rankInFolders($emails[2], [$inbox, $archive]));
    }

    public function testRankInFoldersReturnsNullForEmailsOutsideTheFolderSet(): void
    {
        [, $inbox, $archive, $emails] = $this->seedFolderMailSet();

        self::assertNull($this->repository->rankInFolders($emails[0], [$archive]));
        self::assertNull($this->repository->rankInFolders($emails[0], []));
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Repository;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
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
}

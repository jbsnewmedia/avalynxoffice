<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests;

use AvalynxOffice\DataFixtures\AppFixtures;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('integration')]
class FixturesIntegrityTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);

        // Load fixtures
        $fixtures = static::getContainer()->get(AppFixtures::class);
        $fixtures->load($this->em);
    }

    public function testEmailFoldersHaveValidLinks(): void
    {
        $repo = $this->em->getRepository(EmailFolder::class);
        $all = $repo->findAll();
        $this->assertNotEmpty($all, 'Expected some EmailFolder links to be created by fixtures.');
        foreach ($all as $link) {
            $this->assertNotNull($link->getEmail(), 'EmailFolder link must reference an Email');
            $this->assertNotNull($link->getFolder(), 'EmailFolder link must reference a Folder');
        }
    }

    public function testAllEmailFoldersPointToAccountFolders(): void
    {
        $efRepo = $this->em->getRepository(EmailFolder::class);
        $links = $efRepo->findAll();
        $folderIdsWithAccount = [];
        $accounts = $this->em->getRepository(Account::class)->findAll();
        foreach ($accounts as $mb) {
            foreach ($mb->getFolderLinks() as $link) {
                $f = $link->getFolder();
                if ($f) {
                    $folderIdsWithAccount[(string) $f->getId()] = true;
                }
            }
        }
        foreach ($links as $l) {
            $this->assertArrayHasKey((string) $l->getFolder()->getId(), $folderIdsWithAccount, 'Every EmailFolder folder should be linked to at least one account');
        }
    }

    public function testEachAccountHasAtLeastOneFolderWithEmails(): void
    {
        $accounts = $this->em->getRepository(Account::class)->findAll();
        $this->assertNotEmpty($accounts);
        foreach ($accounts as $mb) {
            $folders = $mb->getFolders()->toArray();
            $this->assertNotEmpty($folders, 'Account should have folders');

            $hasEmails = false;
            foreach ($folders as $folder) {
                $count = $this->em->createQueryBuilder()
                    ->select('COUNT(ef.id)')
                    ->from(EmailFolder::class, 'ef')
                    ->andWhere('ef.folder = :f')
                    ->setParameter('f', $folder)
                    ->getQuery()
                    ->getSingleScalarResult();
                if ((int) $count > 0) {
                    $hasEmails = true;
                    break;
                }
            }
            $this->assertTrue($hasEmails, 'Expected account to have at least one folder containing emails');
        }
    }

    public function testEachAccountHasAliasesAndAddresses(): void
    {
        $accounts = $this->em->getRepository(Account::class)->findAll();
        $this->assertNotEmpty($accounts);
        foreach ($accounts as $account) {
            $this->assertNotEmpty($account->getAliases(), 'Expected account to have aliases');
            $this->assertNotEmpty($account->getAddresses(), 'Expected account to have assigned addresses');
        }

        $connectors = $this->em->getRepository(Connector::class)->findAll();
        $this->assertNotEmpty($connectors);
        foreach ($connectors as $connector) {
            $this->assertNotNull($connector->getDefaultAccount(), 'Expected connector to have a default account');
        }
    }
}

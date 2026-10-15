<?php

declare(strict_types=1);

namespace App\Tests;

use App\DataFixtures\AppFixtures;
use App\Entity\EmailFolder;
use App\Entity\Mailbox;
use App\Tests\Support\RefreshDatabaseTrait;
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

    public function testAllEmailFoldersPointToMailboxFolders(): void
    {
        $efRepo = $this->em->getRepository(EmailFolder::class);
        $links = $efRepo->findAll();
        $folderIdsWithMailbox = [];
        $mailboxes = $this->em->getRepository(Mailbox::class)->findAll();
        foreach ($mailboxes as $mb) {
            foreach ($mb->getFolderLinks() as $link) {
                $f = $link->getFolder();
                if ($f) {
                    $folderIdsWithMailbox[(string) $f->getId()] = true;
                }
            }
        }
        foreach ($links as $l) {
            $this->assertArrayHasKey((string) $l->getFolder()->getId(), $folderIdsWithMailbox, 'Every EmailFolder folder should be linked to at least one mailbox');
        }
    }

    public function testEachMailboxHasAtLeastOneFolderWithEmails(): void
    {
        $mailboxes = $this->em->getRepository(Mailbox::class)->findAll();
        $this->assertNotEmpty($mailboxes);
        foreach ($mailboxes as $mb) {
            $folders = $mb->getFolders()->toArray();
            $this->assertNotEmpty($folders, 'Mailbox should have folders');

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
            $this->assertTrue($hasEmails, 'Expected mailbox to have at least one folder containing emails');
        }
    }

    public function testEachMailboxHasPrimaryConnectorAndAddresses(): void
    {
        $mailboxes = $this->em->getRepository(Mailbox::class)->findAll();
        $this->assertNotEmpty($mailboxes);
        foreach ($mailboxes as $mailbox) {
            $this->assertNotNull($mailbox->getPrimaryConnector(), 'Expected mailbox to have a primary connector');
            $this->assertNotEmpty($mailbox->getAddresses(), 'Expected mailbox to have assigned addresses');
        }
    }
}

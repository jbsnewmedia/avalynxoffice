<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
class MailFolderSelectionControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->refreshDatabase($this->entityManager);
    }

    public function testDeepLinkSelectsRequestedEmailInFolderView(): void
    {
        [$user, $folder, $selectedEmail] = $this->createFixtureData(12, 2);

        $this->client->loginUser($user);
        $this->client->request('GET', sprintf('/mails/all/%s/%s', $folder->getId(), $selectedEmail->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf('.email-item.email-active[data-email="%s"]', $selectedEmail->getId()));
        $this->assertSelectorTextContains('#email-detail', 'Mail 02');
    }

    public function testDeepLinkFallsBackToFirstVisibleEmailWhenUnknownEmailIsRequested(): void
    {
        [$user, $folder] = $this->createFixtureData(3, 1);

        $unknownEmail = new Email();
        $unknownEmail->setSubject('Nicht im Ordner');
        $this->entityManager->persist($unknownEmail);
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', sprintf('/mails/all/%s/%s', $folder->getId(), $unknownEmail->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.email-item.email-active[data-email]');
        $this->assertSelectorTextContains('#email-detail', 'Mail 03');
    }

    /**
     * @return array{0: User, 1: Folder, 2: Email}
     */
    private function createFixtureData(int $emailCount, int $selectedIndex): array
    {
        $mailbox = new Mailbox();
        $mailbox->setName('Support');
        $mailbox->setEmail('support@example.com');

        $folder = new Folder();
        $folder->setName('Posteingang');

        $mailbox->addFolder($folder);

        $user = new User();
        $user->setEmail('user@example.com');
        $user->setPassword('secret');
        $user->addMailbox($mailbox);

        $selectedEmail = null;

        $this->entityManager->persist($mailbox);
        $this->entityManager->persist($folder);
        $this->entityManager->persist($user);

        for ($index = 1; $index <= $emailCount; ++$index) {
            $email = new Email();
            $email->setMailbox($mailbox);
            $email->setSubject(sprintf('Mail %02d', $index));
            $email->setPreviewBody(sprintf('Vorschau %02d', $index));
            $email->setReceivedAt(new \DateTimeImmutable(sprintf('2026-01-%02d 10:00:00', $index)));

            $link = new EmailFolder();
            $link->setFolder($folder);
            $email->addEmailFolder($link);

            $this->entityManager->persist($email);
            $this->entityManager->persist($link);

            if ($index === $selectedIndex) {
                $selectedEmail = $email;
            }
        }

        $this->entityManager->flush();
        self::assertInstanceOf(Email::class, $selectedEmail);

        $userId = (string) $user->getId();
        $folderId = (string) $folder->getId();
        $selectedEmailId = (string) $selectedEmail->getId();
        $this->entityManager->clear();

        /** @var User $freshUser */
        $freshUser = $this->entityManager->find(User::class, $userId);
        /** @var Folder $freshFolder */
        $freshFolder = $this->entityManager->find(Folder::class, $folderId);
        /** @var Email $freshSelectedEmail */
        $freshSelectedEmail = $this->entityManager->find(Email::class, $selectedEmailId);

        return [$freshUser, $freshFolder, $freshSelectedEmail];
    }
}

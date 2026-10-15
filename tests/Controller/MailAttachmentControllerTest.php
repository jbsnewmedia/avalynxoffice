<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Attachment;
use App\Entity\Email;
use App\Entity\EmailAttachmentLink;
use App\Entity\Mailbox;
use App\Entity\User;
use App\Service\Mail\AttachmentStorage;
use App\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
class MailAttachmentControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AttachmentStorage $attachmentStorage;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->attachmentStorage = $container->get(AttachmentStorage::class);

        $baseDir = $this->attachmentStorage->getBaseDir();
        if (is_dir($baseDir)) {
            $this->removeDirectory($baseDir);
        }

        $this->refreshDatabase($this->entityManager);
    }

    public function testAuthorizedUserCanDownloadAttachment(): void
    {
        [$user, $email, $attachment, $payload] = $this->createFixtureData(true);

        $this->client->loginUser($user);
        $this->client->request('GET', sprintf('/email/%s/attachment/%s', $email->getId(), $attachment->getId()));

        $response = $this->client->getResponse();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertSame((string) strlen($payload), (string) $response->headers->get('Content-Length'));
    }

    public function testUnauthorizedUserGetsForbidden(): void
    {
        [, $email, $attachment] = $this->createFixtureData(true);

        $mailbox = new Mailbox();
        $mailbox->setName('Andere Mailbox');
        $mailbox->setEmail('other@example.com');

        $user = new User();
        $user->setEmail('reader@example.com');
        $user->setPassword('secret');
        $user->addMailbox($mailbox);

        $this->entityManager->persist($mailbox);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->loginUser($user);
        $this->client->request('GET', sprintf('/email/%s/attachment/%s', $email->getId(), $attachment->getId()));

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * @return array{0: User, 1: Email, 2: Attachment, 3: string}
     */
    private function createFixtureData(bool $assignMailboxToUser): array
    {
        $mailbox = new Mailbox();
        $mailbox->setName('Inbox');
        $mailbox->setEmail('inbox@example.com');

        $user = new User();
        $user->setEmail('user@example.com');
        $user->setPassword('secret');
        if ($assignMailboxToUser) {
            $user->addMailbox($mailbox);
        }

        $email = new Email();
        $email->setMailbox($mailbox);
        $email->setSubject('Angebot');

        $attachment = new Attachment();
        $attachment->setName('angebot.pdf');
        $attachment->setMimeType('application/pdf');

        $payload = '%PDF-1.4 test';
        $attachment->setSize(strlen($payload));
        $attachment->setSha1(sha1($payload));
        $attachment->setFilePath($this->attachmentStorage->store($attachment, $payload));

        $link = new EmailAttachmentLink();
        $link->setEmail($email);
        $link->setAttachment($attachment);
        $email->addEmailAttachment($link);

        $this->entityManager->persist($mailbox);
        $this->entityManager->persist($user);
        $this->entityManager->persist($email);
        $this->entityManager->persist($attachment);
        $this->entityManager->persist($link);
        $this->entityManager->flush();

        return [$user, $email, $attachment, $payload];
    }

    private function removeDirectory(string $directory): void
    {
        $items = scandir($directory);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $directory.'/'.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\MailActionBundle\Service;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Repository\EmailFolderRepository;
use Doctrine\ORM\EntityManagerInterface;
use JBSNewMedia\MailActionBundle\Service\EmailActionService;
use PHPUnit\Framework\TestCase;

final class EmailActionServiceTest extends TestCase
{
    public function testMarkAsReadUpdatesFlagAndFlushes(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createStub(EmailFolderRepository::class);
        $service = new EmailActionService($entityManager, $repository);
        $email = new Email();

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $service->markAsRead($email, true);

        self::assertTrue($email->isRead());
    }

    public function testMoveEmailReplacesExistingFolderLinks(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EmailFolderRepository::class);
        $service = new EmailActionService($entityManager, $repository);

        $email = new Email();
        $existingOne = (new EmailFolder())->setEmail($email)->setFolder((new Folder())->setName('Inbox'));
        $existingTwo = (new EmailFolder())->setEmail($email)->setFolder((new Folder())->setName('Archive'));
        $targetFolder = (new Folder())->setName('Target');

        $repository
            ->expects(self::exactly(2))
            ->method('findBy')
            ->with(['email' => $email])
            ->willReturnOnConsecutiveCalls([$existingOne, $existingTwo], [$existingOne, $existingTwo]);

        $entityManager
            ->expects(self::exactly(2))
            ->method('remove')
            ->with(self::logicalOr(
                self::identicalTo($existingOne),
                self::identicalTo($existingTwo),
            ));

        $capturedLink = null;
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (object $value) use (&$capturedLink, $email, $targetFolder): bool {
                if (!$value instanceof EmailFolder) {
                    return false;
                }

                $capturedLink = $value;

                return $value->getEmail() === $email && $value->getFolder() === $targetFolder;
            }));

        $entityManager
            ->expects(self::exactly(2))
            ->method('flush');

        $service->moveEmail($email, $targetFolder);

        self::assertInstanceOf(EmailFolder::class, $capturedLink);
    }

    public function testMoveEmailKeepsMailboxFolderAssignment(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EmailFolderRepository::class);
        $service = new EmailActionService($entityManager, $repository);

        $email = new Email();
        $mailboxLink = (new EmailFolder())->setEmail($email)->setFolder((new Folder())->setName('Postfach')->setFunctions(['mailbox']));
        $inboxLink = (new EmailFolder())->setEmail($email)->setFolder((new Folder())->setName('Posteingang')->setFunctions(['inbox']));
        $targetFolder = (new Folder())->setName('Target');

        $repository
            ->expects(self::exactly(2))
            ->method('findBy')
            ->with(['email' => $email])
            ->willReturnOnConsecutiveCalls([$mailboxLink, $inboxLink], [$mailboxLink]);

        $entityManager
            ->expects(self::once())
            ->method('remove')
            ->with(self::identicalTo($inboxLink));

        $capturedLink = null;
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (object $value) use (&$capturedLink, $email, $targetFolder): bool {
                if (!$value instanceof EmailFolder) {
                    return false;
                }

                $capturedLink = $value;

                return $value->getEmail() === $email && $value->getFolder() === $targetFolder;
            }));

        $service->moveEmail($email, $targetFolder);

        self::assertInstanceOf(EmailFolder::class, $capturedLink);
    }

    public function testMoveEmailRestoresMissingMailboxAssignment(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EmailFolderRepository::class);
        $service = new EmailActionService($entityManager, $repository);

        $account = new Account();
        $mailboxFolder = (new Folder())->setName('Postfach')->setFunctions(['mailbox']);
        $account->addFolder($mailboxFolder);

        $email = new Email();
        $email->setAccount($account);
        $inboxLink = (new EmailFolder())->setEmail($email)->setFolder((new Folder())->setName('Posteingang')->setFunctions(['inbox']));
        $targetFolder = (new Folder())->setName('Target');

        $repository
            ->expects(self::exactly(2))
            ->method('findBy')
            ->with(['email' => $email])
            ->willReturnOnConsecutiveCalls([$inboxLink], []);

        $entityManager
            ->expects(self::once())
            ->method('remove')
            ->with(self::identicalTo($inboxLink));

        $persistedFolders = [];
        $entityManager
            ->expects(self::exactly(2))
            ->method('persist')
            ->with(self::callback(static function (object $value) use (&$persistedFolders, $email): bool {
                if (!$value instanceof EmailFolder) {
                    return false;
                }

                $persistedFolders[] = $value->getFolder()?->getName();

                return $value->getEmail() === $email;
            }));

        $service->moveEmail($email, $targetFolder);

        self::assertSame(['Target', 'Postfach'], $persistedFolders);
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\MailActionBundle\Service;

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
            ->expects(self::once())
            ->method('findBy')
            ->with(['email' => $email])
            ->willReturn([$existingOne, $existingTwo]);

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
}

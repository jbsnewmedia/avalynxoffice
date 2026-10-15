<?php

declare(strict_types=1);

namespace JBSNewMedia\MailActionBundle\Service;

use App\Entity\Email;
use App\Entity\EmailFolder;
use App\Entity\Folder;
use App\Repository\EmailFolderRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class EmailActionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailFolderRepository $emailFolderRepository,
    ) {
    }

    public function markAsRead(Email $email, bool $isRead = true): void
    {
        $email->setIsRead($isRead);
        $this->entityManager->flush();
    }

    public function moveEmail(Email $email, Folder $targetFolder): void
    {
        $emailFolders = $this->emailFolderRepository->findBy(['email' => $email]);
        foreach ($emailFolders as $emailFolder) {
            $this->entityManager->remove($emailFolder);
        }
        $this->entityManager->flush();

        $newEmailFolder = new EmailFolder();
        $newEmailFolder->setEmail($email);
        $newEmailFolder->setFolder($targetFolder);
        $this->entityManager->persist($newEmailFolder);
        $this->entityManager->flush();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\MailController;
use App\Entity\Email;
use App\Repository\EmailRepository;
use App\Repository\FolderRepository;
use App\Repository\MailboxRepository;
use App\Service\Mail\EmailDetailTemplateResolver;
use App\Service\Mail\FolderManager;
use App\Service\Mail\MailService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Tests\Support\AllowAllLicenseEnforcement;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MailControllerUnitTest extends TestCase
{
    public function testDenyAccessToEmailRejectsRequestWithoutAuthenticatedUser(): void
    {
        $controller = new class(
            $this->createStub(TranslatorInterface::class),
            $this->createStub(MailService::class),
            $this->createStub(FolderManager::class),
            new EmailDetailTemplateResolver(),
            $this->createStub(MailboxRepository::class),
            $this->createStub(FolderRepository::class),
            $this->createStub(EmailRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new AllowAllLicenseEnforcement(),
        ) extends MailController {
            public function callDenyAccessToEmail(Email $email): void
            {
                $this->denyAccessToEmail($email);
            }

            public function getUser(): ?UserInterface
            {
                return null;
            }
        };

        $this->expectException(AccessDeniedException::class);

        $controller->callDenyAccessToEmail(new Email());
    }
}

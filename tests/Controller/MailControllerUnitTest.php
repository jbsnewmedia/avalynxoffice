<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Controller\MailController;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Repository\FolderRepository;
use AvalynxOffice\Repository\AccountRepository;
use AvalynxOffice\Service\Mail\EmailDetailTemplateResolver;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use AvalynxOffice\Tests\Support\AllowAllLicenseEnforcement;
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
            $this->createStub(AccountRepository::class),
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

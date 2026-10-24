<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Mail;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailRoutingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MailRoutingServiceUnitTest extends TestCase
{
    public function testMoveEmailSkipsFolderLinksWithoutFolder(): void
    {
        $account = (new Account())->setName('Info')->setEmail('info@example.com');
        $target = (new Account())->setName('Support')->setEmail('support@example.com');
        $connector = (new Connector())->setName('Example')->setDomain('example.com');

        $email = new Email();
        $orphanLink = $this->createStub(EmailFolder::class);
        $orphanLink->method('getFolder')->willReturn(null);
        $email->addEmailFolder($orphanLink);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::atLeastOnce())->method('refresh');
        $entityManager->expects(self::once())->method('persist')->with($email);

        $folderManager = $this->createMock(FolderManager::class);
        $folderManager->expects(self::once())->method('syncEmailSystemFolders')->with($email);

        $service = new MailRoutingService(
            $entityManager,
            $this->createStub(\AvalynxOffice\Repository\AccountAliasRepository::class),
            $this->createStub(\AvalynxOffice\Repository\ConnectorRepository::class),
            $folderManager,
        );

        $method = new \ReflectionMethod(MailRoutingService::class, 'moveEmailToAccount');

        self::assertTrue($method->invoke($service, $email, $target));
        self::assertSame($target, $email->getAccount());
    }
}

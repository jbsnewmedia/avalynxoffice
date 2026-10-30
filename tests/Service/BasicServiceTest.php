<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\Body;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailAddressLink;
use AvalynxOffice\Entity\EmailAttachmentLink;
use AvalynxOffice\Entity\EmailBodyLink;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\BodyContentType;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAlias;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Repository\FolderRepository;
use AvalynxOffice\Service\AppService;
use AvalynxOffice\Service\DataService;
use AvalynxOffice\Service\ConnectorService;
use AvalynxOffice\Service\Mail\MailRoutingService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class BasicServiceTest extends TestCase
{
    public function testAppServiceReturnsVersionFromComposerAndFallsBackToDev(): void
    {
        $projectDir = sys_get_temp_dir().'/mailer-app-service-'.bin2hex(random_bytes(4));
        mkdir($projectDir, 0777, true);
        file_put_contents($projectDir.'/composer.json', json_encode(['version' => '1.2.3']));

        $parameterBag = $this->createStub(ParameterBagInterface::class);
        $parameterBag->method('get')
            ->willReturnCallback(static fn (string $name): string => 'kernel.project_dir' === $name ? $projectDir : '');

        $service = new AppService($parameterBag);

        self::assertSame('1.2.3', $service->getVersion());

        file_put_contents($projectDir.'/composer.json', '{invalid-json');
        self::assertSame('dev', $service->getVersion());

        unlink($projectDir.'/composer.json');
        rmdir($projectDir);
        self::assertSame('dev', $service->getVersion());
    }

    public function testAccountConnectorServicePersistsFlushesAndRemoves(): void
    {
        $connector = new Connector();

        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with($connector);
        $entityManager->expects(self::exactly(3))
            ->method('flush');
        $entityManager->expects(self::once())
            ->method('remove')
            ->with($connector);

        $service = new ConnectorService($entityManager, $this->createStub(MailRoutingService::class));
        $service->save($connector, true);
        $service->save($connector, false);
        $service->delete($connector);
    }

    public function testDataServiceMapsAccountFolderEmailAndAttachmentData(): void
    {
        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');

        $connector = (new Connector())
            ->setName('Primary')
            ->setDomain('example.com');
        $alias = (new AccountAlias())
            ->setAccount($account)
            ->setConnector($connector)
            ->setLocalPart('alias');
        $account->addAlias($alias);
        $account->addAddress((new Address())->setEmail('alias@example.com')->setName('Alias'));

        $folder = (new Folder())->setName('Inbox');
        $email = (new Email())
            ->setSubject('Subject')
            ->setPreviewBody('Preview')
            ->setReceivedAt(new \DateTimeImmutable('2024-01-02T03:04:05+00:00'));

        $addressLink = (new EmailAddressLink())
            ->setAddress((new Address())->setEmail('sender@example.com')->setName('Sender'))
            ->setType(EmailAddressType::SENDER);
        $bodyLink = (new EmailBodyLink())
            ->setBody((new Body())->setContent('Body content'))
            ->setContentType(BodyContentType::HTML);
        $attachmentLink = (new EmailAttachmentLink())
            ->setAttachment(
                (new Attachment())
                    ->setName('invoice.pdf')
                    ->setSize(321)
                    ->setMimeType('application/pdf')
                    ->setSha1('sha')
                    ->setFilePath('/tmp/invoice.pdf')
            );

        $email->addEmailAddress($addressLink);
        $email->addEmailBody($bodyLink);
        $email->addEmailAttachment($attachmentLink);

        $emailFolder = (new EmailFolder())
            ->setEmail($email)
            ->setFolder($folder);
        $email->addEmailFolder($emailFolder);
        $this->setPrivateProperty($folder, 'emailFolders', new ArrayCollection([$emailFolder]));

        $folderRepository = $this->createStub(FolderRepository::class);
        $folderRepository->method('findByAccountOrderByAssignment')
            ->willReturnCallback(static fn (Account $value): array => $value === $account ? [$folder] : []);

        $data = (new DataService($folderRepository))->getAllData([$account]);

        self::assertCount(1, $data);
        self::assertSame('Support', $data[0]['name']);
        self::assertSame('support@example.com', $data[0]['email']);
        self::assertSame('alias', $data[0]['aliases'][0]['localPart']);
        self::assertSame('alias@example.com', $data[0]['aliases'][0]['address']);
        self::assertSame('alias@example.com', $data[0]['addresses'][0]['email']);
        self::assertSame('Inbox', $data[0]['folders'][0]['name']);
        self::assertSame('Subject', $data[0]['folders'][0]['emails'][0]['subject']);
        self::assertSame('Preview', $data[0]['folders'][0]['emails'][0]['previewBody']);
        self::assertSame('sender@example.com', $data[0]['folders'][0]['emails'][0]['addresses'][0]['address']['email']);
        self::assertSame('Body content', $data[0]['folders'][0]['emails'][0]['bodies'][0]['body']['content']);
        self::assertSame('invoice.pdf', $data[0]['folders'][0]['emails'][0]['attachments'][0]['attachment']['name']);
        self::assertArrayNotHasKey('filePath', $data[0]['folders'][0]['emails'][0]['attachments'][0]['attachment']);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }
}

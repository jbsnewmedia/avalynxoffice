<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\MailActionBundle\Controller;

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
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\EmailFolderRepository;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Repository\FolderRepository;
use AvalynxOffice\Repository\AccountRepository;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use JBSNewMedia\MailActionBundle\Controller\MailActionController;
use JBSNewMedia\MailActionBundle\Service\EmailActionService;
use JBSNewMedia\MailActionBundle\Service\MailDraftService;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use AvalynxOffice\Tests\Support\AllowAllLicenseEnforcement;

#[Group('integration')]
final class MailActionControllerTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $entityManager;
    private MailActionController $controller;
    private RequestStack $requestStack;
    private bool $attachmentStorageThrows = false;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->entityManager);

        $translator = $container->get('translator');
        $emailRepository = $container->get(EmailRepository::class);
        $folderRepository = $container->get(FolderRepository::class);
        $mailService = $container->get(MailService::class);
        $accountRepository = $container->get(AccountRepository::class);
        $emailFolderRepository = $container->get(EmailFolderRepository::class);
        $folderManager = $this->createStub(FolderManager::class);
        $folderManager->method('syncEmailSystemFolders');
        $attachmentStorage = $this->createStub(AttachmentStorage::class);
        $attachmentStorage->method('store')->willReturnCallback(function (Attachment $attachment, string $payload): string {
            if ($this->attachmentStorageThrows) {
                throw new \RuntimeException('Attachment is too large.');
            }

            return 'stored/'.sha1($payload).'/'.$attachment->getName();
        });

        $emailActionService = new EmailActionService($this->entityManager, $emailFolderRepository);
        $mailDraftService = new MailDraftService($this->entityManager, $accountRepository, $folderManager, $attachmentStorage, $translator, new AllowAllLicenseEnforcement());

        $this->controller = new MailActionController(
            $translator,
            $emailRepository,
            $folderRepository,
            $mailService,
            $emailActionService,
            $mailDraftService,
            new AllowAllLicenseEnforcement(),
        );

        $this->requestStack = $container->get('request_stack');
        $this->controller->setContainer(new ServiceLocator([
            'router' => static fn (): UrlGeneratorInterface => new class implements UrlGeneratorInterface {
                public function generate(string $name, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
                {
                    return match ($name) {
                        'mail_index' => '/mails',
                        'mail_compose' => '/mail/compose',
                        'mail_edit' => '/mail/edit/'.($parameters['id'] ?? '').'?ajax='.($parameters['ajax'] ?? ''),
                        default => '/'.$name,
                    };
                }

                public function setContext(\Symfony\Component\Routing\RequestContext $context): void
                {
                }

                public function getContext(): \Symfony\Component\Routing\RequestContext
                {
                    return new \Symfony\Component\Routing\RequestContext();
                }

                public function getRouteCollection(): ?\Symfony\Component\Routing\RouteCollection
                {
                    return null;
                }
            },
            'twig' => static fn () => static::getContainer()->get('twig'),
            'form.factory' => static fn () => static::getContainer()->get('form.factory'),
            'request_stack' => static fn () => static::getContainer()->get('request_stack'),
        ]));
    }

    public function testSetReadAndMoveEmailUpdateFolderCounts(): void
    {
        [$user, $email, $sourceFolder] = $this->createFixtureData('one');
        $this->setCurrentUserRequest();

        $setReadResponse = $this->controller->setRead((string) $email->getId(), Request::create('/', 'POST', ['read' => '1']));
        self::assertSame(Response::HTTP_OK, $setReadResponse->getStatusCode());
        self::assertSame([
            'success' => true,
            'read' => true,
            'folderCounts' => [(string) $sourceFolder->getId() => 0],
        ], json_decode($setReadResponse->getContent(), true, 512, JSON_THROW_ON_ERROR));

        [$otherUser, $otherEmail, $otherSourceFolder, $otherTargetFolder] = $this->createFixtureData('two');
        $this->setCurrentUserRequest();

        $moveResponse = $this->controller->moveEmail((string) $otherEmail->getId(), Request::create('/', 'POST', [
            'targetFolderId' => (string) $otherTargetFolder->getId(),
            'sourceFolderId' => (string) $otherSourceFolder->getId(),
        ]));

        self::assertSame(Response::HTTP_OK, $moveResponse->getStatusCode());
        self::assertSame([
            'success' => true,
            'folderCounts' => [
                (string) $otherTargetFolder->getId() => 1,
                (string) $otherSourceFolder->getId() => 0,
            ],
        ], json_decode($moveResponse->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSetReadAndMoveEmailReturnErrorsForInvalidInput(): void
    {
        [, $email] = $this->createFixtureData('errors');
        $this->setCurrentUserRequest();

        $missingEmailResponse = $this->controller->setRead('00000000-0000-0000-0000-000000000000', Request::create('/', 'POST'));
        self::assertSame(Response::HTTP_NOT_FOUND, $missingEmailResponse->getStatusCode());

        $missingMoveEmailResponse = $this->controller->moveEmail('00000000-0000-0000-0000-000000000000', Request::create('/', 'POST'));
        self::assertSame(Response::HTTP_NOT_FOUND, $missingMoveEmailResponse->getStatusCode());

        $invalidTargetResponse = $this->controller->moveEmail((string) $email->getId(), Request::create('/', 'POST', [
            'targetFolderId' => 'invalid',
        ]));
        self::assertSame(Response::HTTP_BAD_REQUEST, $invalidTargetResponse->getStatusCode());

        $missingTargetResponse = $this->controller->moveEmail((string) $email->getId(), Request::create('/', 'POST', [
            'targetFolderId' => '00000000-0000-0000-0000-000000000000',
        ]));
        self::assertSame(Response::HTTP_NOT_FOUND, $missingTargetResponse->getStatusCode());
    }

    public function testReplyForwardDeleteComposePrintAndEditBranchesWork(): void
    {
        [$user, $email] = $this->createFixtureData('main');
        $this->setCurrentUserRequest();

        $printResponse = $this->controller->printEmail((string) $email->getId());
        self::assertSame(Response::HTTP_OK, $printResponse->getStatusCode());
        self::assertStringContainsString('Hello world', $printResponse->getContent());

        $replyResponse = $this->controller->reply((string) $email->getId());
        self::assertSame(Response::HTTP_SEE_OTHER, $replyResponse->getStatusCode());
        self::assertStringContainsString('/mail/edit/', (string) $replyResponse->headers->get('Location'));

        $replyAllResponse = $this->controller->replyAll((string) $email->getId());
        self::assertSame(Response::HTTP_SEE_OTHER, $replyAllResponse->getStatusCode());
        self::assertStringContainsString('/mail/edit/', (string) $replyAllResponse->headers->get('Location'));

        $forwardResponse = $this->controller->forwardMail((string) $email->getId());
        self::assertSame(Response::HTTP_SEE_OTHER, $forwardResponse->getStatusCode());
        self::assertStringContainsString('/mail/edit/', (string) $forwardResponse->headers->get('Location'));

        $deleteResponse = $this->controller->delete();
        self::assertSame(Response::HTTP_FOUND, $deleteResponse->getStatusCode());
        self::assertSame('/mails', (string) $deleteResponse->headers->get('Location'));

        $composeResponse = $this->controller->compose(Request::create('/mail/compose', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
        self::assertSame(Response::HTTP_OK, $composeResponse->getStatusCode());
        self::assertStringContainsString('<form', $composeResponse->getContent());

        $editResponse = $this->controller->edit((string) $email->getId(), Request::create('/mail/edit/'.$email->getId(), 'GET'));
        self::assertSame(Response::HTTP_FOUND, $editResponse->getStatusCode());
        self::assertSame('/mails', (string) $editResponse->headers->get('Location'));
    }

    public function testNotFoundBranchesThrowForPrintReplyForwardAndEdit(): void
    {
        $this->setCurrentUserRequest();

        foreach (['printEmail', 'reply', 'replyAll', 'forwardMail'] as $methodName) {
            try {
                $this->controller->{$methodName}('00000000-0000-0000-0000-000000000000');
                self::fail(sprintf('Expected %s() to throw a not found exception.', $methodName));
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                self::assertTrue(true);
            }
        }

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $this->controller->edit('00000000-0000-0000-0000-000000000000', Request::create('/mail/edit/missing', 'GET', ['ajax' => '1']));
    }

    public function testComposeReturnsInvalidPayloadForInvalidSubmission(): void
    {
        $this->createFixtureData('compose-invalid');
        $this->setCurrentUserRequest();
        $this->setControllerFormFactory(static::getContainer()->get('form.factory'));

        $response = $this->controller->compose(Request::create('/mail/compose', 'POST', [
            'email_reply' => [
                'to' => '',
                'content' => 'Hello',
            ],
        ], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('invalid', $data);
        self::assertNotEmpty($data['invalid']);
    }

    public function testComposeRedirectsWhenRequestIsNotAjax(): void
    {
        $response = $this->controller->compose(Request::create('/mail/compose', 'GET'));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/mails', (string) $response->headers->get('Location'));
    }

    public function testComposeReturnsSubmissionResponseForAjaxPost(): void
    {
        $this->createFixtureData('compose-return');
        $form = $this->createSubmittedDraftForm();
        $formFactory = $this->createStub(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);
        $this->setControllerFormFactory($formFactory);
        $this->setCurrentUserRequest();

        $response = $this->controller->compose(Request::create('/mail/compose', 'POST', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame('/mails', $data['redirect']);
    }

    public function testEditRedirectsWhenEmailCannotBeEdited(): void
    {
        [, $email] = $this->createFixtureData('locked');
        $this->setCurrentUserRequest();

        $response = $this->controller->edit((string) $email->getId(), Request::create('/mail/edit/'.$email->getId(), 'GET', ['ajax' => '1']));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/mails', (string) $response->headers->get('Location'));
    }

    public function testEditRendersDraftWithBodyRecipientsSendersAndAttachments(): void
    {
        $email = $this->createEditableDraftWithFormDefaults();
        $this->setCurrentUserRequest();

        $response = $this->controller->edit((string) $email->getId(), Request::create('/mail/edit/'.$email->getId(), 'GET', ['ajax' => '1']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('<form', $response->getContent());
        self::assertStringContainsString('Draft content with links', $response->getContent());
        self::assertStringContainsString('Support editable &lt;sender.named@example.com&gt;', $response->getContent());
        self::assertStringContainsString('recipient@example.com', $response->getContent());
        self::assertStringContainsString('stored.txt', $response->getContent());
    }

    public function testEditReturnsSubmissionResponseForAjaxPost(): void
    {
        $email = $this->createPersistedDraftEmail('edit-response');
        $form = $this->createSubmittedDraftForm();
        $formFactory = $this->createStub(FormFactoryInterface::class);
        $formFactory->method('create')->willReturn($form);
        $this->setControllerFormFactory($formFactory);
        $this->setCurrentUserRequest();

        $response = $this->controller->edit((string) $email->getId(), Request::create('/mail/edit/'.$email->getId(), 'POST', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame('/mails', $data['redirect']);
    }

    public function testCreateDraftFormPrefillsRecipientsAndContent(): void
    {
        $email = (new Email())
            ->setSubject('Draft subject');

        $recipient = (new Address())->setEmail('to@example.com');
        $recipientLink = (new EmailAddressLink())
            ->setEmail($email)
            ->setAddress($recipient)
            ->setType(EmailAddressType::RECIPIENT);
        $email->addEmailAddress($recipientLink);

        $body = (new Body())->setContent('Draft content');
        $bodyLink = (new EmailBodyLink())
            ->setEmail($email)
            ->setBody($body)
            ->setContentType(BodyContentType::TEXT);
        $email->addEmailBody($bodyLink);

        $method = new \ReflectionMethod(MailActionController::class, 'createDraftForm');
        $form = $method->invoke($this->controller, $email, '/mail/compose');

        self::assertSame('to@example.com', $form->get('to')->getData());
        self::assertSame('Draft content', $form->get('content')->getData());
    }

    public function testHandleDraftSubmissionReturnsJsonForAjaxComposeSubmission(): void
    {
        $email = new Email();
        $this->setCurrentUserRequest();
        $controller = $this->controller;

        $form = $this->createStub(FormInterface::class);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $toField = $this->createStub(FormInterface::class);
        $toField->method('getData')->willReturn('to@example.com');
        $fromField = $this->createStub(FormInterface::class);
        $fromField->method('getData')->willReturn(null);
        $ccField = $this->createStub(FormInterface::class);
        $ccField->method('getData')->willReturn('');
        $bccField = $this->createStub(FormInterface::class);
        $bccField->method('getData')->willReturn('');
        $replyToField = $this->createStub(FormInterface::class);
        $replyToField->method('getData')->willReturn('');
        $contentField = $this->createStub(FormInterface::class);
        $contentField->method('getData')->willReturn('Draft content');
        $retainedAttachmentsField = $this->createStub(FormInterface::class);
        $retainedAttachmentsField->method('getData')->willReturn('');
        $scheduleLaterField = $this->createStub(FormInterface::class);
        $scheduleLaterField->method('getData')->willReturn(false);
        $scheduledAtField = $this->createStub(FormInterface::class);
        $scheduledAtField->method('getData')->willReturn(null);
        $attachmentsField = $this->createStub(FormInterface::class);
        $attachmentsField->method('getData')->willReturn([]);
        $saveDraftButton = new \Symfony\Component\Form\SubmitButtonBuilder('saveDraft');
        $saveDraftButton = $saveDraftButton->getForm();

        $form->method('get')->willReturnMap([
            ['from', $fromField],
            ['to', $toField],
            ['cc', $ccField],
            ['bcc', $bccField],
            ['replyTo', $replyToField],
            ['content', $contentField],
            ['retainedAttachmentIds', $retainedAttachmentsField],
            ['scheduleLater', $scheduleLaterField],
            ['scheduledAt', $scheduledAtField],
            ['attachments', $attachmentsField],
            ['saveDraft', $saveDraftButton],
        ]);

        $method = new \ReflectionMethod(MailActionController::class, 'handleDraftSubmission');
        $response = $method->invoke($controller, $email, $form, Request::create('/mail/compose', 'GET', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']), true);

        self::assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame('/mails', $data['redirect']);
    }

    public function testHandleDraftSubmissionReturnsNullWhenFormIsNotReady(): void
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('isSubmitted')->willReturn(false);

        $method = new \ReflectionMethod(MailActionController::class, 'handleDraftSubmission');

        self::assertNull($method->invoke($this->controller, new Email(), $form, Request::create('/mail/compose', 'GET'), false));
    }

    public function testHandleDraftSubmissionClosesModalWhenComposeSaveDraftIsClicked(): void
    {
        $email = new Email();
        $this->setCurrentUserRequest();
        $controller = $this->controller;

        $form = $this->createStub(FormInterface::class);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $toField = $this->createStub(FormInterface::class);
        $toField->method('getData')->willReturn('to@example.com');
        $fromField = $this->createStub(FormInterface::class);
        $fromField->method('getData')->willReturn(null);
        $ccField = $this->createStub(FormInterface::class);
        $ccField->method('getData')->willReturn('');
        $bccField = $this->createStub(FormInterface::class);
        $bccField->method('getData')->willReturn('');
        $replyToField = $this->createStub(FormInterface::class);
        $replyToField->method('getData')->willReturn('');
        $contentField = $this->createStub(FormInterface::class);
        $contentField->method('getData')->willReturn('Draft content');
        $retainedAttachmentsField = $this->createStub(FormInterface::class);
        $retainedAttachmentsField->method('getData')->willReturn('');
        $scheduleLaterField = $this->createStub(FormInterface::class);
        $scheduleLaterField->method('getData')->willReturn(false);
        $scheduledAtField = $this->createStub(FormInterface::class);
        $scheduledAtField->method('getData')->willReturn(null);
        $attachmentsField = $this->createStub(FormInterface::class);
        $attachmentsField->method('getData')->willReturn([]);
        $saveDraftButton = new \Symfony\Component\Form\SubmitButtonBuilder('saveDraft');
        $saveDraftButton = $saveDraftButton->getForm();
        $saveDraftButton->submit('1');

        $form->method('get')->willReturnMap([
            ['from', $fromField],
            ['to', $toField],
            ['cc', $ccField],
            ['bcc', $bccField],
            ['replyTo', $replyToField],
            ['content', $contentField],
            ['retainedAttachmentIds', $retainedAttachmentsField],
            ['scheduleLater', $scheduleLaterField],
            ['scheduledAt', $scheduledAtField],
            ['attachments', $attachmentsField],
            ['saveDraft', $saveDraftButton],
        ]);

        $method = new \ReflectionMethod(MailActionController::class, 'handleDraftSubmission');
        $request = Request::create('/mail/compose', 'GET', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $response = $method->invoke($controller, $email, $form, $request, false);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame('/mails', $data['redirect']);
        self::assertSame('Draft saved.', $data['message']);
    }

    public function testHandleDraftSubmissionThrowsForNonStringFormValues(): void
    {
        $email = new Email();
        $this->setCurrentUserRequest();

        $form = $this->createStub(FormInterface::class);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $toField = $this->createStub(FormInterface::class);
        $toField->method('getData')->willReturn(['invalid']);
        $fromField = $this->createStub(FormInterface::class);
        $fromField->method('getData')->willReturn(null);
        $ccField = $this->createStub(FormInterface::class);
        $ccField->method('getData')->willReturn('');
        $bccField = $this->createStub(FormInterface::class);
        $bccField->method('getData')->willReturn('');
        $replyToField = $this->createStub(FormInterface::class);
        $replyToField->method('getData')->willReturn('');
        $contentField = $this->createStub(FormInterface::class);
        $contentField->method('getData')->willReturn('Draft content');
        $retainedAttachmentsField = $this->createStub(FormInterface::class);
        $retainedAttachmentsField->method('getData')->willReturn('');
        $scheduleLaterField = $this->createStub(FormInterface::class);
        $scheduleLaterField->method('getData')->willReturn(false);
        $scheduledAtField = $this->createStub(FormInterface::class);
        $scheduledAtField->method('getData')->willReturn(null);
        $attachmentsField = $this->createStub(FormInterface::class);
        $attachmentsField->method('getData')->willReturn([]);
        $saveDraftButton = new \Symfony\Component\Form\SubmitButtonBuilder('saveDraft');
        $saveDraftButton = $saveDraftButton->getForm();

        $form->method('get')->willReturnMap([
            ['from', $fromField],
            ['to', $toField],
            ['cc', $ccField],
            ['bcc', $bccField],
            ['replyTo', $replyToField],
            ['content', $contentField],
            ['retainedAttachmentIds', $retainedAttachmentsField],
            ['scheduleLater', $scheduleLaterField],
            ['scheduledAt', $scheduledAtField],
            ['attachments', $attachmentsField],
            ['saveDraft', $saveDraftButton],
        ]);

        $method = new \ReflectionMethod(MailActionController::class, 'handleDraftSubmission');

        $this->expectException(\LogicException::class);
        $method->invoke($this->controller, $email, $form, Request::create('/mail/compose', 'GET'), false);
    }

    public function testHandleDraftSubmissionRequiresScheduledDateWhenDelayedSendingIsEnabled(): void
    {
        $email = new Email();
        $this->setCurrentUserRequest();

        $form = $this->createStub(FormInterface::class);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $toField = $this->createStub(FormInterface::class);
        $toField->method('getData')->willReturn('to@example.com');
        $fromField = $this->createStub(FormInterface::class);
        $fromField->method('getData')->willReturn(null);
        $ccField = $this->createStub(FormInterface::class);
        $ccField->method('getData')->willReturn('');
        $bccField = $this->createStub(FormInterface::class);
        $bccField->method('getData')->willReturn('');
        $replyToField = $this->createStub(FormInterface::class);
        $replyToField->method('getData')->willReturn('');
        $contentField = $this->createStub(FormInterface::class);
        $contentField->method('getData')->willReturn('Draft content');
        $retainedAttachmentsField = $this->createStub(FormInterface::class);
        $retainedAttachmentsField->method('getData')->willReturn('');
        $scheduleLaterField = $this->createStub(FormInterface::class);
        $scheduleLaterField->method('getData')->willReturn(true);
        $scheduledAtField = $this->createMock(FormInterface::class);
        $scheduledAtField->method('getData')->willReturn(null);
        $scheduledAtField->expects(self::once())->method('addError');
        $attachmentsField = $this->createStub(FormInterface::class);
        $attachmentsField->method('getData')->willReturn([]);
        $saveDraftButton = new \Symfony\Component\Form\SubmitButtonBuilder('saveDraft');
        $saveDraftButton = $saveDraftButton->getForm();

        $form->method('get')->willReturnMap([
            ['from', $fromField],
            ['to', $toField],
            ['cc', $ccField],
            ['bcc', $bccField],
            ['replyTo', $replyToField],
            ['content', $contentField],
            ['retainedAttachmentIds', $retainedAttachmentsField],
            ['scheduleLater', $scheduleLaterField],
            ['scheduledAt', $scheduledAtField],
            ['attachments', $attachmentsField],
            ['saveDraft', $saveDraftButton],
        ]);

        $method = new \ReflectionMethod(MailActionController::class, 'handleDraftSubmission');
        $response = $method->invoke($this->controller, $email, $form, Request::create('/mail/compose', 'GET'), false);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response?->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame('Please choose a date for scheduled sending.', $data['invalid']['email_reply[scheduledAt]'] ?? null);
    }

    public function testHandleDraftSubmissionThrowsForInvalidAttachmentValues(): void
    {
        $email = new Email();
        $this->setCurrentUserRequest();
        $form = $this->createSubmittedDraftForm(['attachments' => [new \stdClass()]]);

        $this->expectException(\LogicException::class);
        $this->invokeHandleDraftSubmission($email, $form, Request::create('/mail/compose', 'GET'), false);
    }

    public function testHandleDraftSubmissionThrowsForInvalidScheduledDateValue(): void
    {
        $form = $this->createSubmittedDraftForm([
            'scheduleLater' => true,
            'scheduledAt' => 'tomorrow',
        ]);

        $this->expectException(\LogicException::class);
        $this->invokeHandleDraftSubmission(new Email(), $form, Request::create('/mail/compose', 'GET'), false);
    }

    public function testHandleDraftSubmissionThrowsWhenAttachmentsFieldIsNotArrayOrNull(): void
    {
        $form = $this->createSubmittedDraftForm(['attachments' => 'invalid']);

        $this->expectException(\LogicException::class);
        $this->invokeHandleDraftSubmission(new Email(), $form, Request::create('/mail/compose', 'GET'), false);
    }

    public function testHandleDraftSubmissionStoresUploadedAttachmentAndScheduledDate(): void
    {
        $email = $this->createPersistedDraftEmail('upload');
        $uploadedFile = $this->createUploadedFile('scheduled-upload.txt', 'attachment content');

        try {
            $response = $this->invokeHandleDraftSubmission($email, $this->createSubmittedDraftForm([
                'attachments' => [$uploadedFile],
                'scheduleLater' => true,
                'scheduledAt' => new \DateTimeImmutable('2027-01-02 03:04:00'),
            ]), Request::create('/mail/compose', 'POST', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']), false);
        } finally {
            $this->removeUploadedFile($uploadedFile);
        }

        self::assertSame(Response::HTTP_OK, $response?->getStatusCode());
        self::assertFalse($email->isDraft());
        self::assertEquals(new \DateTimeImmutable('2027-01-02 03:04:00'), $email->getScheduledAt());
        self::assertCount(1, $email->getEmailAttachments());
    }

    public function testHandleDraftSubmissionAddsAttachmentErrorWhenSaveThrowsRuntimeException(): void
    {
        $this->attachmentStorageThrows = true;
        $uploadedFile = $this->createUploadedFile('too-large.txt', 'too large');
        $attachmentsField = $this->createMock(FormInterface::class);
        $attachmentsField->method('getData')->willReturn([$uploadedFile]);
        $attachmentsField->expects(self::once())->method('addError');

        try {
            $response = $this->invokeHandleDraftSubmission(new Email(), $this->createSubmittedDraftForm(
                ['attachments' => [$uploadedFile]],
                false,
                $attachmentsField
            ), Request::create('/mail/compose', 'POST'), false);
        } finally {
            $this->attachmentStorageThrows = false;
            $this->removeUploadedFile($uploadedFile);
        }

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response?->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('email_reply[attachments]', $data['invalid']);
    }

    public function testHandleDraftSubmissionReturnsEditRedirectForNonAjaxSend(): void
    {
        $response = $this->invokeHandleDraftSubmission(
            $this->createPersistedDraftEmail('edit-send'),
            $this->createSubmittedDraftForm(),
            Request::create('/mail/edit', 'POST'),
            true
        );

        self::assertSame(Response::HTTP_FOUND, $response?->getStatusCode());
        self::assertSame('/mails', (string) $response?->headers->get('Location'));
    }

    public function testHandleDraftSubmissionReturnsNullForNonAjaxEditDraftSave(): void
    {
        $response = $this->invokeHandleDraftSubmission(
            $this->createPersistedDraftEmail('edit-save'),
            $this->createSubmittedDraftForm(saveDraftClicked: true),
            Request::create('/mail/edit', 'POST'),
            true
        );

        self::assertNull($response);
    }

    public function testHandleDraftSubmissionRedirectsComposeSendForNonAjaxRequest(): void
    {
        $response = $this->invokeHandleDraftSubmission(
            $this->createPersistedDraftEmail('compose-send'),
            $this->createSubmittedDraftForm(),
            Request::create('/mail/compose', 'POST'),
            false
        );

        self::assertSame(Response::HTTP_FOUND, $response?->getStatusCode());
        self::assertSame('/mails', (string) $response?->headers->get('Location'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createSubmittedDraftForm(array $overrides = [], bool $saveDraftClicked = false, ?FormInterface $attachmentsField = null): FormInterface
    {
        $form = $this->createStub(FormInterface::class);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $data = array_replace([
            'from' => 'sender@example.com',
            'to' => 'recipient@example.com',
            'cc' => '',
            'bcc' => '',
            'replyTo' => '',
            'content' => 'Draft content',
            'retainedAttachmentIds' => '',
            'scheduleLater' => false,
            'scheduledAt' => null,
            'attachments' => [],
        ], $overrides);

        $fields = [];
        foreach ($data as $name => $value) {
            if ('attachments' === $name && null !== $attachmentsField) {
                $fields[$name] = $attachmentsField;
                continue;
            }

            $field = $this->createStub(FormInterface::class);
            $field->method('getData')->willReturn($value);
            $field->method('setData')->willReturn($field);
            $fields[$name] = $field;
        }

        $saveDraftButton = new \Symfony\Component\Form\SubmitButtonBuilder('saveDraft');
        $saveDraftButton = $saveDraftButton->getForm();
        if ($saveDraftClicked) {
            $saveDraftButton->submit('1');
        }

        $form->method('get')->willReturnCallback(static function (string $name) use ($fields, $saveDraftButton): FormInterface {
            if ('saveDraft' === $name) {
                return $saveDraftButton;
            }

            if (!isset($fields[$name])) {
                throw new \LogicException('Unknown form field '.$name);
            }

            return $fields[$name];
        });

        return $form;
    }

    private function setControllerFormFactory(FormFactoryInterface $formFactory): void
    {
        $this->controller->setContainer(new ServiceLocator([
            'router' => static fn (): UrlGeneratorInterface => new class implements UrlGeneratorInterface {
                public function generate(string $name, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
                {
                    return match ($name) {
                        'mail_index' => '/mails',
                        'mail_compose' => '/mail/compose',
                        'mail_edit' => '/mail/edit/'.($parameters['id'] ?? '').'?ajax='.($parameters['ajax'] ?? ''),
                        default => '/'.$name,
                    };
                }

                public function setContext(\Symfony\Component\Routing\RequestContext $context): void
                {
                }

                public function getContext(): \Symfony\Component\Routing\RequestContext
                {
                    return new \Symfony\Component\Routing\RequestContext();
                }

                public function getRouteCollection(): ?\Symfony\Component\Routing\RouteCollection
                {
                    return null;
                }
            },
            'twig' => static fn () => static::getContainer()->get('twig'),
            'form.factory' => static fn (): FormFactoryInterface => $formFactory,
            'request_stack' => static fn () => static::getContainer()->get('request_stack'),
        ]));
    }

    private function invokeHandleDraftSubmission(Email $email, FormInterface $form, Request $request, bool $redirectDraftToEdit): ?Response
    {
        $this->setCurrentUserRequest();
        $method = new \ReflectionMethod(MailActionController::class, 'handleDraftSubmission');

        $response = $method->invoke($this->controller, $email, $form, $request, $redirectDraftToEdit);
        self::assertNull($response instanceof Response ? null : $response);

        return $response;
    }

    private function createPersistedDraftEmail(string $suffix): Email
    {
        [$user] = $this->createFixtureData('draft-'.$suffix);
        $account = $user->getAccounts()->first();
        self::assertInstanceOf(Account::class, $account);

        $email = (new Email())
            ->setAccount($account)
            ->setSubject('Draft '.$suffix)
            ->setPreviewBody('Draft preview')
            ->setIsOutgoing(true)
            ->setIsDraft(true);

        $this->entityManager->persist($email);
        $this->entityManager->flush();

        return $email;
    }

    private function createEditableDraftWithFormDefaults(): Email
    {
        [$user] = $this->createFixtureData('editable');
        $account = $user->getAccounts()->first();
        self::assertInstanceOf(Account::class, $account);

        $connector = (new Connector())
            ->setName('Example')
            ->setDomain('example.com');
        $this->entityManager->persist($connector);
        foreach (['', 'sender.named', 'plain.sender'] as $localPart) {
            if ('' === $localPart) {
                continue;
            }
            $alias = (new AccountAlias())
                ->setAccount($account)
                ->setConnector($connector)
                ->setLocalPart($localPart);
            $account->addAlias($alias);
            $this->entityManager->persist($alias);
        }

        $email = (new Email())
            ->setAccount($account)
            ->setSubject('Editable draft')
            ->setPreviewBody('Editable preview')
            ->setIsOutgoing(true)
            ->setIsDraft(true);

        $this->entityManager->persist($email);
        $this->entityManager->persist($account);
        $this->addEmailAddress($email, EmailAddressType::SENDER, 'sender.named@example.com', 'Named Sender');
        $this->addEmailAddress($email, EmailAddressType::RECIPIENT, 'recipient@example.com', 'Recipient Name');
        $this->addEmailAddress($email, EmailAddressType::CC, 'cc@example.com', null);
        $this->addEmailAddress($email, EmailAddressType::BCC, 'bcc@example.com', 'Blind Copy');
        $this->addEmailAddress($email, EmailAddressType::REPLY_TO, 'reply-to@example.com', 'Reply Name');

        $body = (new Body())->setContent('Draft content with links');
        $bodyLink = (new EmailBodyLink())
            ->setEmail($email)
            ->setBody($body)
            ->setContentType(BodyContentType::TEXT);
        $email->addEmailBody($bodyLink);

        $attachment = (new Attachment())
            ->setName('stored.txt')
            ->setSize(123)
            ->setMimeType('text/plain')
            ->setSha1(str_repeat('a', 40))
            ->setFilePath('stored/stored.txt');
        $attachmentLink = (new EmailAttachmentLink())
            ->setEmail($email)
            ->setAttachment($attachment);
        $email->addEmailAttachment($attachmentLink);

        $this->entityManager->persist($body);
        $this->entityManager->persist($bodyLink);
        $this->entityManager->persist($attachment);
        $this->entityManager->persist($attachmentLink);
        $this->entityManager->flush();

        $email->removeEmailBody($bodyLink);
        $nullBodyLink = (new EmailBodyLink())->setBody(null)->setContentType(BodyContentType::TEXT);
        $email->addEmailBody($nullBodyLink);
        $email->addEmailBody($bodyLink);
        $email->addEmailAttachment((new EmailAttachmentLink())->setAttachment(null));

        return $email;
    }

    private function addEmailAddress(Email $email, EmailAddressType $type, string $mail, ?string $name): void
    {
        $address = (new Address())->setEmail($mail)->setName($name);
        $link = (new EmailAddressLink())
            ->setEmail($email)
            ->setAddress($address)
            ->setType($type);
        $email->addEmailAddress($link);

        $this->entityManager->persist($address);
        $this->entityManager->persist($link);
    }

    private function createUploadedFile(string $name, string $content): UploadedFile
    {
        $directory = static::getContainer()->getParameter('kernel.project_dir').'/var/test-uploads';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/'.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/plain', null, true);
    }

    private function removeUploadedFile(UploadedFile $file): void
    {
        $path = $file->getPathname();
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @return array{0: User, 1: Email, 2: Folder, 3?: Folder}
     */
    private function createFixtureData(string $suffix): array
    {
        $account = (new Account())
            ->setName('Support '.$suffix)
            ->setEmail($suffix.'+support@example.com');

        $sourceFolder = (new Folder())->setName('Inbox');
        $targetFolder = (new Folder())->setName('Archive');
        $account->addFolder($sourceFolder);
        $account->addFolder($targetFolder);

        $user = (new User())
            ->setEmail($suffix.'+user@example.com')
            ->setPassword('secret');
        $user->addAccount($account);

        $email = (new Email())
            ->setAccount($account)
            ->setSubject('Hello world '.$suffix)
            ->setPreviewBody('Hello world preview')
            ->setReceivedAt(new \DateTimeImmutable('2026-04-27 10:15:00'));

        $sender = (new Address())->setEmail('sender@example.com');
        $recipient = (new Address())->setEmail('support@example.com');
        $senderLink = (new EmailAddressLink())
            ->setEmail($email)
            ->setAddress($sender)
            ->setType(EmailAddressType::SENDER);
        $recipientLink = (new EmailAddressLink())
            ->setEmail($email)
            ->setAddress($recipient)
            ->setType(EmailAddressType::RECIPIENT);
        $body = (new Body())->setContent('Hello world');
        $bodyLink = (new EmailBodyLink())
            ->setEmail($email)
            ->setBody($body)
            ->setContentType(BodyContentType::TEXT);
        $folderLink = (new EmailFolder())
            ->setEmail($email)
            ->setFolder($sourceFolder);

        $email->addEmailAddress($senderLink);
        $email->addEmailAddress($recipientLink);
        $email->addEmailBody($bodyLink);
        $email->addEmailFolder($folderLink);

        $this->entityManager->persist($account);
        $this->entityManager->persist($sourceFolder);
        $this->entityManager->persist($targetFolder);
        $this->entityManager->persist($user);
        $this->entityManager->persist($email);
        $this->entityManager->persist($sender);
        $this->entityManager->persist($recipient);
        $this->entityManager->persist($body);
        $this->entityManager->persist($senderLink);
        $this->entityManager->persist($recipientLink);
        $this->entityManager->persist($bodyLink);
        $this->entityManager->persist($folderLink);
        $this->entityManager->flush();

        return [$user, $email, $sourceFolder, $targetFolder];
    }

    private function setCurrentUserRequest(): void
    {
        $request = Request::create('/');
        $request->setLocale('en');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);
    }

    public function testHandleDraftSubmissionReturnsJsonForAjaxDraftSaveInEditMode(): void
    {
        $response = $this->invokeHandleDraftSubmission(
            $this->createPersistedDraftEmail('ajax-draft-save'),
            $this->createSubmittedDraftForm(saveDraftClicked: true),
            Request::create('/mail/edit', 'POST', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']),
            true,
        );

        self::assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertNull($data['redirect']);
        self::assertArrayHasKey('message', $data);
    }

    public function testCreateSenderChoicesSkipsBlankAndDuplicateAddresses(): void
    {
        $connector = (new Connector())
            ->setName('Support')
            ->setDomain('example.com');
        $account = (new Account())
            ->setName('Support')
            ->setEmail('');
        $account->addAlias((new AccountAlias())->setLocalPart('alias')->setAccount($account)->setConnector($connector));
        $account->addAlias((new AccountAlias())->setLocalPart('ALIAS')->setAccount($account)->setConnector($connector));

        $email = (new Email())->setAccount($account)->setSubject('Choices');

        $method = new \ReflectionMethod(MailActionController::class, 'createSenderChoices');
        $choices = $method->invoke($this->controller, $email);

        self::assertSame(
            [
                'Support <alias@example.com>' => 'alias@example.com',
            ],
            $choices,
        );
    }
}

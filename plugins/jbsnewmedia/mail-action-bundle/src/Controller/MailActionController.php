<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\MailActionBundle\Controller;

use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Form\EmailReplyType;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Repository\FolderRepository;
use AvalynxOffice\Service\Mail\MailService;
use JBSNewMedia\MailActionBundle\Service\EmailActionService;
use JBSNewMedia\MailActionBundle\Service\MailDraftService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MailActionController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly EmailRepository $emailRepository,
        private readonly FolderRepository $folderRepository,
        private readonly MailService $mailService,
        private readonly EmailActionService $emailActionService,
        private readonly MailDraftService $mailDraftService,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    #[Route(path: '/email/{id}/read', name: 'mail_email_read', requirements: ['id' => '[0-9a-fA-F\-]{36}'], methods: ['POST'])]
    public function setRead(string $id, Request $request): JsonResponse
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::MAIL_MODIFY);
        $email = $this->emailRepository->find($id);
        if (!$email instanceof Email) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('email.not_found')], Response::HTTP_NOT_FOUND);
        }

        $param = $request->request->get('read') ?? $request->query->get('read');
        $read = in_array($param, ['1', 'true', 1, true], true);
        $this->emailActionService->markAsRead($email, $read);

        $folders = [];
        foreach ($email->getEmailFolders() as $emailFolder) {
            $folder = $emailFolder->getFolder();
            if ($folder instanceof Folder) {
                $folders[(string) $folder->getId()] = $folder;
            }
        }

        return new JsonResponse([
            'success' => true,
            'read' => $email->isRead(),
            'folderCounts' => $this->mailService->countEmailsPerFolder(array_values($folders)),
        ]);
    }

    #[Route(path: '/email/{id}/print', name: 'mail_email_print', requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function printEmail(string $id): Response
    {
        $email = $this->emailRepository->find($id);
        if (!$email instanceof Email) {
            throw $this->createNotFoundException($this->translator->trans('email.not_found'));
        }

        return $this->render('mail/print.html.twig', [
            'email' => $email,
        ]);
    }

    #[Route(path: '/email/{id}/move', name: 'mail_email_move', requirements: ['id' => '[0-9a-fA-F\-]{36}'], methods: ['POST'])]
    public function moveEmail(string $id, Request $request): JsonResponse
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::MAIL_MODIFY);
        $email = $this->emailRepository->find($id);
        if (!$email instanceof Email) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('email.not_found')], Response::HTTP_NOT_FOUND);
        }

        $targetId = (string) $request->request->get('targetFolderId', '');
        if ('' === $targetId || 1 !== preg_match('/^[0-9a-fA-F\-]{36}$/', $targetId)) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('folder.target_invalid')], Response::HTTP_BAD_REQUEST);
        }

        $target = $this->folderRepository->find($targetId);
        if (!$target instanceof Folder) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('folder.target_not_found')], Response::HTTP_NOT_FOUND);
        }

        $sourceId = (string) $request->request->get('sourceFolderId', '');
        $this->emailActionService->moveEmail($email, $target);

        $affectedFolders = [$target];
        if ('' !== $sourceId) {
            $sourceFolder = $this->folderRepository->find($sourceId);
            if ($sourceFolder instanceof Folder) {
                $affectedFolders[] = $sourceFolder;
            }
        }

        return new JsonResponse([
            'success' => true,
            'folderCounts' => $this->mailService->countEmailsPerFolder($affectedFolders),
        ]);
    }

    #[Route('/mail/reply/{id}', name: 'mail_reply')]
    public function reply(string $id): Response
    {
        $originalEmail = $this->emailRepository->find($id);
        if (!$originalEmail instanceof Email) {
            throw $this->createNotFoundException($this->translator->trans('email.not_found'));
        }

        $draft = $this->mailDraftService->createReplyDraft($originalEmail);

        return $this->redirectToRoute('mail_edit', ['id' => $draft->getId(), 'ajax' => '1'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/mail/reply-all/{id}', name: 'mail_reply_all')]
    public function replyAll(string $id): Response
    {
        $originalEmail = $this->emailRepository->find($id);
        if (!$originalEmail instanceof Email) {
            throw $this->createNotFoundException($this->translator->trans('email.not_found'));
        }

        $draft = $this->mailDraftService->createReplyAllDraft($originalEmail);

        return $this->redirectToRoute('mail_edit', ['id' => $draft->getId(), 'ajax' => '1'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/mail/edit/{id}', name: 'mail_edit')]
    public function edit(string $id, Request $request): Response
    {
        if (!$request->isXmlHttpRequest() && '1' !== $request->query->get('ajax')) {
            return $this->redirectToRoute('mail_index');
        }

        $email = $this->emailRepository->find($id);
        if (!$email instanceof Email) {
            throw $this->createNotFoundException($this->translator->trans('email.not_found'));
        }

        if (!$email->isDraft() && !($email->isOutgoing() && !$email->isSent())) {
            $this->addFlash('error', $this->translator->trans('email.cannot_edit'));

            return $this->redirectToRoute('mail_index');
        }

        $form = $this->createDraftForm($email, $this->generateUrl('mail_edit', ['id' => $email->getId()]));
        $form->handleRequest($request);

        if ($response = $this->handleDraftSubmission($email, $form, $request, true)) {
            return $response;
        }

        return $this->render('mail/_modal_form.html.twig', [
            'form' => $form->createView(),
            'email' => $email,
            'recipientAutocompleteDefaults' => $this->buildRecipientAutocompleteDefaults($email),
        ]);
    }

    #[Route('/mail/forward/{id}', name: 'mail_forward')]
    public function forwardMail(string $id): Response
    {
        $originalEmail = $this->emailRepository->find($id);
        if (!$originalEmail instanceof Email) {
            throw $this->createNotFoundException($this->translator->trans('email.not_found'));
        }

        $draft = $this->mailDraftService->createForwardDraft($originalEmail);

        return $this->redirectToRoute('mail_edit', ['id' => $draft->getId(), 'ajax' => '1'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/mail/delete/{id}', name: 'mail_delete', methods: ['POST', 'DELETE'])]
    public function delete(): Response
    {
        $this->addFlash('info', $this->translator->trans('email.delete_not_implemented'));

        return $this->redirectToRoute('mail_index');
    }

    #[Route('/mail/compose', name: 'mail_compose')]
    public function compose(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }

        $email = $this->mailDraftService->createComposeDraft();
        $form = $this->createDraftForm($email, $this->generateUrl('mail_compose'));
        $form->handleRequest($request);

        if ($response = $this->handleDraftSubmission($email, $form, $request, false)) {
            return $response;
        }

        return $this->render('mail/_modal_form.html.twig', [
            'form' => $form->createView(),
            'email' => $email,
            'recipientAutocompleteDefaults' => $this->buildRecipientAutocompleteDefaults($email),
        ]);
    }

    /**
     * @return FormInterface<Email>
     */
    private function createDraftForm(Email $email, string $action): FormInterface
    {
        $content = '';
        foreach ($email->getEmailBodies() as $bodyLink) {
            $body = $bodyLink->getBody();
            if (null === $body) {
                continue;
            }

            $content = $body->getContent();
            break;
        }

        $form = $this->createForm(EmailReplyType::class, $email, [
            'action' => $action,
            'sender_choices' => $this->createSenderChoices($email),
        ]);
        $form->get('from')->setData($this->extractFirstAddress($email, EmailAddressType::SENDER));
        $form->get('to')->setData($this->extractAddressList($email, EmailAddressType::RECIPIENT));
        $form->get('cc')->setData($this->extractAddressList($email, EmailAddressType::CC));
        $form->get('bcc')->setData($this->extractAddressList($email, EmailAddressType::BCC));
        $form->get('replyTo')->setData($this->extractAddressList($email, EmailAddressType::REPLY_TO));
        $form->get('content')->setData($content);
        $form->get('scheduleLater')->setData(null !== $email->getScheduledAt());
        $form->get('scheduledAt')->setData($email->getScheduledAt());
        $form->get('retainedAttachmentIds')->setData(implode(',', $this->extractAttachmentIds($email)));

        return $form;
    }

    /**
     * @param FormInterface<Email> $form
     */
    private function handleDraftSubmission(Email $email, FormInterface $form, Request $request, bool $redirectDraftToEdit): ?Response
    {
        if (!$form->isSubmitted() || !$form->isValid()) {
            return null;
        }

        $fromValue = $form->get('from')->getData();
        $toValue = $form->get('to')->getData();
        $ccValue = $form->get('cc')->getData();
        $bccValue = $form->get('bcc')->getData();
        $replyToValue = $form->get('replyTo')->getData();
        $contentValue = $form->get('content')->getData();
        $retainedAttachmentIdsValue = $form->get('retainedAttachmentIds')->getData();
        $scheduleLaterValue = $form->get('scheduleLater')->getData();
        $scheduledAtValue = $form->get('scheduledAt')->getData();
        $attachmentsValue = $form->get('attachments')->getData();
        if ((!is_string($fromValue) && null !== $fromValue)
            || !is_string($toValue)
            || !is_string($ccValue)
            || !is_string($bccValue)
            || !is_string($replyToValue)
            || !is_string($contentValue)
            || !is_string($retainedAttachmentIdsValue)
            || !is_bool($scheduleLaterValue)
        ) {
            throw new \LogicException('Mail draft form values must be strings.');
        }

        if (null !== $scheduledAtValue && !$scheduledAtValue instanceof \DateTimeInterface) {
            throw new \LogicException('Mail draft schedule must be a date or null.');
        }

        $uploadedAttachments = [];
        if (is_array($attachmentsValue)) {
            foreach ($attachmentsValue as $attachment) {
                if (!$attachment instanceof UploadedFile) {
                    throw new \LogicException('Mail draft attachments must be uploaded files.');
                }

                $uploadedAttachments[] = $attachment;
            }
        } elseif (null !== $attachmentsValue) {
            throw new \LogicException('Mail draft attachments must be uploaded files.');
        }

        $saveDraftButton = $form->get('saveDraft');
        $isDraft = $saveDraftButton instanceof ClickableInterface && $saveDraftButton->isClicked();

        if ($scheduleLaterValue && null === $scheduledAtValue) {
            $form->get('scheduledAt')->addError(new FormError($this->translator->trans('email.scheduled_at_required')));

            return null;
        }

        $scheduledAt = $scheduleLaterValue && $scheduledAtValue instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($scheduledAtValue)
            : null;

        try {
            $this->mailDraftService->saveEmailFromForm(
                $email,
                $fromValue,
                $toValue,
                $ccValue,
                $bccValue,
                $replyToValue,
                $contentValue,
                $isDraft,
                $scheduledAt,
                $this->parseAttachmentIds($retainedAttachmentIdsValue),
                $uploadedAttachments,
            );
        } catch (\RuntimeException $exception) {
            $form->get('attachments')->addError(new FormError($exception->getMessage()));

            return null;
        }

        $this->addFlash('success', $this->translator->trans($isDraft ? 'email.draft_saved' : 'email.queued'));

        if ($redirectDraftToEdit) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true]);
            }

            if (!$isDraft) {
                return $this->redirectToRoute('mail_index');
            }

            return null;
        }

        if ($isDraft) {
            return $this->redirectToRoute('mail_edit', ['id' => $email->getId(), 'ajax' => '1']);
        }

        return $request->isXmlHttpRequest()
            ? new JsonResponse(['success' => true])
            : $this->redirectToRoute('mail_index');
    }

    /**
     * @return array<string, string>
     */
    private function createSenderChoices(Email $email): array
    {
        $mailbox = $email->getMailbox();
        if (!$mailbox instanceof Mailbox) {
            return [];
        }

        $choices = [];
        $seen = [];
        foreach ($mailbox->getAddresses() as $address) {
            $mail = trim((string) $address->getEmail());
            if ('' === $mail) {
                continue;
            }

            $normalized = mb_strtolower($mail);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $label = $address->getName() ? sprintf('%s <%s>', $address->getName(), $mail) : $mail;
            $choices[$label] = $mail;
        }

        $mail = trim($mailbox->getEmail());
        if ('' !== $mail) {
            $normalized = mb_strtolower($mail);
            if (!isset($seen[$normalized])) {
                $choices[$mailbox->getName() ? sprintf('%s <%s>', $mailbox->getName(), $mail) : $mail] = $mail;
            }
        }

        return $choices;
    }

    private function extractFirstAddress(Email $email, EmailAddressType $type): ?string
    {
        foreach ($email->getEmailAddresses() as $link) {
            if ($type !== $link->getType()) {
                continue;
            }

            return $link->getAddress()?->getEmail();
        }

        return null;
    }

    private function extractAddressList(Email $email, EmailAddressType $type): string
    {
        $addresses = [];
        foreach ($email->getEmailAddresses() as $link) {
            $address = $link->getAddress();
            if ($type !== $link->getType() || null === $address) {
                continue;
            }

            $addresses[] = $address->getEmail();
        }

        return implode(', ', $addresses);
    }

    /**
     * @return array{to: list<array{key: string, value: string}>, cc: list<array{key: string, value: string}>, bcc: list<array{key: string, value: string}>, replyTo: list<array{key: string, value: string}>}
     */
    private function buildRecipientAutocompleteDefaults(Email $email): array
    {
        return [
            'to' => $this->buildAutocompleteSelections($email, EmailAddressType::RECIPIENT),
            'cc' => $this->buildAutocompleteSelections($email, EmailAddressType::CC),
            'bcc' => $this->buildAutocompleteSelections($email, EmailAddressType::BCC),
            'replyTo' => $this->buildAutocompleteSelections($email, EmailAddressType::REPLY_TO),
        ];
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function buildAutocompleteSelections(Email $email, EmailAddressType $type): array
    {
        $selections = [];
        foreach ($email->getEmailAddresses() as $link) {
            $address = $link->getAddress();
            if ($type !== $link->getType() || null === $address) {
                continue;
            }

            $selections[] = [
                'key' => $address->getEmail(),
                'value' => $address->getName()
                    ? sprintf('%s <%s>', $address->getName(), $address->getEmail())
                    : $address->getEmail(),
            ];
        }

        return $selections;
    }

    /**
     * @return list<string>
     */
    private function extractAttachmentIds(Email $email): array
    {
        $attachmentIds = [];
        foreach ($email->getEmailAttachments() as $link) {
            $attachment = $link->getAttachment();
            if (null === $attachment?->getId()) {
                continue;
            }

            $attachmentIds[] = (string) $attachment->getId();
        }

        return $attachmentIds;
    }

    /**
     * @return list<string>
     */
    private function parseAttachmentIds(string $value): array
    {
        return array_values(array_filter(array_map(
            trim(...),
            explode(',', $value)
        ), static fn (string $item): bool => '' !== $item));
    }
}

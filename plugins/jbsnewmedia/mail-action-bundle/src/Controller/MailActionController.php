<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\MailActionBundle\Controller;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Form\EmailReplyType;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Repository\FolderRepository;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailService;
use AvalynxOffice\Service\Mail\UserAccountProvider;
use AvalynxOffice\Util\AvalynxFormResponse;
use AvalynxOffice\Util\FormInvalidMapper;
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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
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
        private readonly FolderManager $folderManager,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
        private readonly UserAccountProvider $userAccountProvider,
        private readonly TokenStorageInterface $tokenStorage,
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
        $this->assertEmailAccessible($email);

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
        $this->assertEmailAccessible($email);

        return $this->render('mail/print.html.twig', [
            'email' => $email,
        ]);
    }

    #[Route(path: '/email/{id}/move', name: 'mail_email_move', requirements: ['id' => '[0-9a-fA-F\-]{36}'], methods: ['GET', 'POST'])]
    public function moveEmail(string $id, Request $request): Response
    {
        $email = $this->emailRepository->find($id);
        if (!$email instanceof Email) {
            if ($request->isMethod('POST')) {
                return new JsonResponse(['success' => false, 'error' => $this->translator->trans('email.not_found')], Response::HTTP_NOT_FOUND);
            }

            throw $this->createNotFoundException($this->translator->trans('email.not_found'));
        }
        $this->assertEmailAccessible($email);

        if (!$request->isMethod('POST')) {
            // Folder picker rendered into the action modal for the move button.
            if (!$request->isXmlHttpRequest()) {
                return $this->redirectToRoute('mail_index');
            }

            $account = $email->getAccount();
            $folders = $account instanceof Account ? $this->folderRepository->findByAccountOrderByAssignment($account) : [];
            $currentFolderIds = [];
            foreach ($email->getEmailFolders() as $emailFolder) {
                $folder = $emailFolder->getFolder();
                if ($folder instanceof Folder) {
                    $currentFolderIds[] = (string) $folder->getId();
                }
            }

            return $this->render('@MailAction/mail_action/email/actions/move_form.html.twig', [
                'email' => $email,
                'folders' => $folders,
                'currentFolderIds' => $currentFolderIds,
            ]);
        }

        $this->licenseEnforcement->assertCanPerform(LicenseOperation::MAIL_MODIFY);
        if (!$this->isCsrfTokenValid('move_email', (string) $request->request->get('_token', ''))) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('common.invalid_token')], Response::HTTP_FORBIDDEN);
        }

        $targetId = (string) $request->request->get('targetFolderId', '');
        if ('' === $targetId || 1 !== preg_match('/^[0-9a-fA-F\-]{36}$/', $targetId)) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('folder.target_invalid')], Response::HTTP_BAD_REQUEST);
        }

        $target = $this->folderRepository->find($targetId);
        if (!$target instanceof Folder) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('folder.target_not_found')], Response::HTTP_NOT_FOUND);
        }
        $this->assertFolderAccessible($target, $email->getAccount());

        $sourceId = (string) $request->request->get('sourceFolderId', '');
        $leftFolderIds = $this->resolveLeftFolderIds($email, $target);
        $this->emailActionService->moveEmail($email, $target);

        $affectedFolders = [$target];
        if ('' !== $sourceId) {
            $sourceFolder = $this->folderRepository->find($sourceId);
            if ($sourceFolder instanceof Folder) {
                $this->assertFolderAccessible($sourceFolder, $email->getAccount());
                $affectedFolders[] = $sourceFolder;
            }
        }

        return new JsonResponse([
            'success' => true,
            'folderCounts' => $this->mailService->countEmailsPerFolder($affectedFolders),
            'leftFolderIds' => $leftFolderIds,
        ]);
    }

    /**
     * Folder ids the mail leaves behind when moved to the target: all its
     * current folders except the target itself and Postfach (which always
     * keeps every mail).
     *
     * @return list<string>
     */
    private function resolveLeftFolderIds(Email $email, Folder $target): array
    {
        $left = [];
        foreach ($email->getEmailFolders() as $emailFolder) {
            $folder = $emailFolder->getFolder();
            if (!$folder instanceof Folder) {
                continue;
            }

            $folderId = (string) $folder->getId();
            if ($folderId === (string) $target->getId() || $folder->hasFunction('mailbox')) {
                continue;
            }

            $left[] = $folderId;
        }

        return array_values(array_unique($left));
    }

    #[Route('/mail/reply/{id}', name: 'mail_reply', methods: ['POST'])]
    public function reply(string $id, Request $request): Response
    {
        $this->assertAjaxActionRequest($request, 'reply_email_'.$id);
        $originalEmail = $this->emailRepository->find($id);
        if (!$originalEmail instanceof Email) {
            throw $this->createNotFoundException($this->translator->trans('email.not_found'));
        }
        $this->assertEmailAccessible($originalEmail);

        $draft = $this->mailDraftService->createReplyDraft($originalEmail);

        return $this->redirectToRoute('mail_edit', ['id' => $draft->getId(), 'ajax' => '1'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/mail/reply-all/{id}', name: 'mail_reply_all', methods: ['POST'])]
    public function replyAll(string $id, Request $request): Response
    {
        $this->assertAjaxActionRequest($request, 'reply_all_email_'.$id);
        $originalEmail = $this->emailRepository->find($id);
        if (!$originalEmail instanceof Email) {
            throw $this->createNotFoundException($this->translator->trans('email.not_found'));
        }
        $this->assertEmailAccessible($originalEmail);

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
        $this->assertEmailAccessible($email);

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

    #[Route('/mail/forward/{id}', name: 'mail_forward', methods: ['POST'])]
    public function forwardMail(string $id, Request $request): Response
    {
        $this->assertAjaxActionRequest($request, 'forward_email_'.$id);
        $originalEmail = $this->emailRepository->find($id);
        if (!$originalEmail instanceof Email) {
            throw $this->createNotFoundException($this->translator->trans('email.not_found'));
        }
        $this->assertEmailAccessible($originalEmail);

        $draft = $this->mailDraftService->createForwardDraft($originalEmail);

        return $this->redirectToRoute('mail_edit', ['id' => $draft->getId(), 'ajax' => '1'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/mail/delete/{id}', name: 'mail_delete', requirements: ['id' => '[0-9a-fA-F\-]{36}'], methods: ['POST', 'DELETE'])]
    public function delete(string $id, Request $request): Response
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::MAIL_MODIFY);
        $email = $this->emailRepository->find($id);
        if (!$email instanceof Email) {
            throw $this->createNotFoundException($this->translator->trans('email.not_found'));
        }
        $this->assertEmailAccessible($email);

        if (!$this->isCsrfTokenValid('delete_email_'.$id, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', $this->translator->trans('common.invalid_token'));

            return $this->redirectToRoute('mail_index');
        }

        $account = $email->getAccount();
        if (!$account instanceof Account) {
            $this->addFlash('error', $this->translator->trans('email.not_found'));

            return $this->redirectToRoute('mail_index');
        }

        // Deleting moves the mail to the account's trash folder, so it stays
        // recoverable; the trash folder is a required system folder.
        $trash = $this->folderManager->ensureSystemFolder($account, FolderFunction::TRASH);
        $this->emailActionService->moveEmail($email, $trash);

        $this->addFlash('success', $this->translator->trans('email.moved_to_trash'));

        $refererPath = $this->safeRefererPath($request);
        if (null !== $refererPath) {
            return $this->redirect($refererPath);
        }

        return $this->redirectToRoute('mail_index');
    }

    #[Route(path: '/email/{id}/spam', name: 'mail_email_spam', requirements: ['id' => '[0-9a-fA-F\-]{36}'], methods: ['POST'])]
    public function markSpam(string $id, Request $request): JsonResponse
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::MAIL_MODIFY);
        $email = $this->emailRepository->find($id);
        if (!$email instanceof Email) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('email.not_found')], Response::HTTP_NOT_FOUND);
        }
        $this->assertEmailAccessible($email);

        if (!$this->isCsrfTokenValid('spam_email_'.$id, (string) $request->request->get('_token', ''))) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('common.invalid_token')], Response::HTTP_FORBIDDEN);
        }

        $account = $email->getAccount();
        if (!$account instanceof Account) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('email.not_found')], Response::HTTP_BAD_REQUEST);
        }

        // The spam folder is created on first use and is not one of the
        // required system folders.
        $spam = $this->folderManager->ensureFolder($account, FolderManager::FOLDER_SPAM, 50);

        $affectedFolders[(string) $spam->getId()] = $spam;
        $leftFolderIds = $this->resolveLeftFolderIds($email, $spam);
        foreach ($email->getEmailFolders() as $emailFolder) {
            $folder = $emailFolder->getFolder();
            if ($folder instanceof Folder) {
                $affectedFolders[(string) $folder->getId()] = $folder;
            }
        }

        $this->emailActionService->moveEmail($email, $spam);

        return new JsonResponse([
            'success' => true,
            'message' => $this->translator->trans('email.marked_as_spam'),
            'folderCounts' => $this->mailService->countEmailsPerFolder(array_values($affectedFolders)),
            'leftFolderIds' => $leftFolderIds,
        ]);
    }

    #[Route('/mail/compose', name: 'mail_compose')]
    public function compose(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        $account = $user instanceof User ? ($this->userAccountProvider->forUser($user)[0] ?? null) : null;
        $email = $this->mailDraftService->createComposeDraft($account);
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
        if (!$form->isSubmitted()) {
            return null;
        }

        if (!$form->isValid()) {
            // AvalynxForm submits via fetch and expects JSON field errors.
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
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
            || (null !== $ccValue && !is_string($ccValue))
            || (null !== $bccValue && !is_string($bccValue))
            || (null !== $replyToValue && !is_string($replyToValue))
            || !is_string($contentValue)
            || (null !== $retainedAttachmentIdsValue && !is_string($retainedAttachmentIdsValue))
            || (null !== $scheduleLaterValue && !is_bool($scheduleLaterValue))
        ) {
            throw new \LogicException('Mail draft form values must be strings.');
        }

        // Unchecked checkboxes are not sent by the browser, so the field
        // resolves to null instead of false.
        $scheduleLaterChecked = true === $scheduleLaterValue;

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

        if ($scheduleLaterChecked && null === $scheduledAtValue) {
            $form->get('scheduledAt')->addError(new FormError($this->translator->trans('email.scheduled_at_required')));

            return AvalynxFormResponse::invalid([
                'email_reply[scheduledAt]' => $this->translator->trans('email.scheduled_at_required'),
            ]);
        }

        $scheduledAt = $scheduleLaterChecked && $scheduledAtValue instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($scheduledAtValue)
            : null;

        try {
            $this->mailDraftService->saveEmailFromForm(
                $email,
                $fromValue,
                $toValue,
                $ccValue ?? '',
                $bccValue ?? '',
                $replyToValue ?? '',
                $contentValue,
                $isDraft,
                $scheduledAt,
                $this->parseAttachmentIds($retainedAttachmentIdsValue ?? ''),
                $uploadedAttachments,
            );
        } catch (\RuntimeException $exception) {
            $form->get('attachments')->addError(new FormError($exception->getMessage()));

            return AvalynxFormResponse::invalid([
                'email_reply[attachments]' => $exception->getMessage(),
            ]);
        }

        $this->addFlash('success', $this->translator->trans($isDraft ? 'email.draft_saved' : 'email.queued'));

        if ($redirectDraftToEdit) {
            if ($request->isXmlHttpRequest()) {
                if ($isDraft) {
                    // Stay in the modal: the draft already has an identity,
                    // saving again is idempotent.
                    return AvalynxFormResponse::success(null, [
                        'message' => $this->translator->trans('email.draft_saved'),
                    ]);
                }

                return AvalynxFormResponse::success($this->generateUrl('mail_index'));
            }

            if (!$isDraft) {
                return $this->redirectToRoute('mail_index');
            }

            return null;
        }

        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }

        // Compose: the response closes the modal — submitting again would
        // create a second draft.
        return AvalynxFormResponse::success($this->generateUrl('mail_index'), $isDraft ? [
            'message' => $this->translator->trans('email.draft_saved'),
        ] : []);
    }

    /**
     * @return array<string, string>
     */
    private function createSenderChoices(Email $email): array
    {
        $account = $email->getAccount();
        if (!$account instanceof Account) {
            return [];
        }

        $choices = [];
        $seen = [];

        $addChoice = static function (string $mail) use (&$choices, &$seen, $account): void {
            $mail = trim($mail);
            if ('' === $mail) {
                return;
            }

            $normalized = mb_strtolower($mail);
            if (isset($seen[$normalized])) {
                return;
            }

            $seen[$normalized] = true;
            $choices[$account->getName() ? sprintf('%s <%s>', $account->getName(), $mail) : $mail] = $mail;
        };

        $addChoice($account->getEmail());
        foreach ($account->getAliases() as $alias) {
            $addChoice($alias->getAddress());
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

    private function assertEmailAccessible(Email $email): void
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        $account = $email->getAccount();
        if (!$user instanceof User || null === $account || !in_array($account, $this->userAccountProvider->forUser($user), true)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function assertFolderAccessible(Folder $folder, ?Account $expectedAccount = null): void
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User || null === $expectedAccount
            || !in_array($expectedAccount, $this->userAccountProvider->forUser($user), true)
            || !$expectedAccount->getFolders()->contains($folder)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function assertAjaxActionRequest(Request $request, string $tokenId): void
    {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException($this->translator->trans('common.invalid_token'));
        }
    }

    private function safeRefererPath(Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        if (!is_string($referer) || '' === $referer) {
            return null;
        }

        $parts = parse_url($referer);
        if (false === $parts || !isset($parts['host'])
            || strtolower($parts['host']) !== strtolower($request->getHost())
            || (isset($parts['scheme']) && strtolower($parts['scheme']) !== strtolower($request->getScheme()))
            || (isset($parts['port']) && $parts['port'] !== $request->getPort())
        ) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        return $path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
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

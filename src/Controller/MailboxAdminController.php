<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Mailbox;
use App\Form\MailboxType;
use App\Licensing\Service\LicenseEnforcementInterface;
use App\Licensing\Service\LicenseOperation;
use App\Repository\MailboxRepository;
use App\Service\MailboxService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/settings/mailboxes')]
class MailboxAdminController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    #[Route('', name: 'mailbox_index', methods: ['GET'])]
    public function index(Request $request, MailboxRepository $repo): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }
        $items = $repo->findBy([], ['name' => 'ASC']);

        return $this->render('mailbox/_modal_index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/view', name: 'mailbox_structure', methods: ['GET'])]
    public function structure(Request $request, MailboxRepository $repo): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }
        $items = $repo->findBy([], ['name' => 'ASC']);

        return $this->render('mailbox/_modal_structure.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/new', name: 'mailbox_new', methods: ['GET', 'POST'])]
    public function new(Request $request, MailboxService $mailboxService): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }
        $mailbox = new Mailbox();
        $form = $this->createForm(MailboxType::class, $mailbox, [
            'action' => $this->generateUrl('mailbox_new'),
            'aliases_data' => $mailboxService->formatAliases($mailbox),
            'folders_data' => $mailboxService->formatFolders($mailbox),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $aliases = $mailboxService->normalizeAliases($this->getTextareaValue($form, 'aliases'));
            $folders = $mailboxService->normalizeFolders($this->getTextareaValue($form, 'folders'));
            $invalidAliases = $mailboxService->findInvalidAliases($aliases);
            $duplicateFunctions = $mailboxService->findDuplicateFolderFunctions($folders);
            $missingFunctions = $mailboxService->findMissingSystemFolderFunctions($folders);
            $hasCustomErrors = false;

            if ([] !== $invalidAliases) {
                $hasCustomErrors = true;
                $form->get('aliases')->addError(new FormError($this->translator->trans('mailbox.invalid_aliases', [
                    '%aliases%' => implode(', ', $invalidAliases),
                ])));
            }

            if ([] !== $duplicateFunctions) {
                $hasCustomErrors = true;
                $form->get('folders')->addError(new FormError($this->translator->trans('mailbox.duplicate_folder_functions', [
                    '%functions%' => implode(', ', array_map(fn (string $key): string => $this->translator->trans($key), $duplicateFunctions)),
                ])));
            }

            if ([] !== $missingFunctions) {
                $hasCustomErrors = true;
                $form->get('folders')->addError(new FormError($this->translator->trans('mailbox.missing_folder_functions', [
                    '%functions%' => implode(', ', array_map(fn (string $key): string => $this->translator->trans($key), $missingFunctions)),
                ])));
            }

            if (!$hasCustomErrors) {
                $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
                $mailboxService->save($mailbox, $aliases, $folders, true);

                return $this->redirectToRoute('mailbox_index', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('mailbox/_modal_form.html.twig', [
            'form' => $form->createView(),
            'title' => $this->translator->trans('mailbox.new'),
        ]);
    }

    #[Route('/{id}/edit', name: 'mailbox_edit', methods: ['GET', 'POST'])]
    public function edit(Mailbox $mailbox, Request $request, MailboxService $mailboxService): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }
        $form = $this->createForm(MailboxType::class, $mailbox, [
            'action' => $this->generateUrl('mailbox_edit', ['id' => $mailbox->getId()]),
            'aliases_data' => $mailboxService->formatAliases($mailbox),
            'folders_data' => $mailboxService->formatFolders($mailbox),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $aliases = $mailboxService->normalizeAliases($this->getTextareaValue($form, 'aliases'));
            $folders = $mailboxService->normalizeFolders($this->getTextareaValue($form, 'folders'));
            $invalidAliases = $mailboxService->findInvalidAliases($aliases);
            $blockedFolders = $mailboxService->findNonRemovableFolders($mailbox, $folders);
            $duplicateFunctions = $mailboxService->findDuplicateFolderFunctions($folders);
            $missingFunctions = $mailboxService->findMissingSystemFolderFunctions($folders);
            $hasCustomErrors = false;

            if ([] !== $invalidAliases) {
                $hasCustomErrors = true;
                $form->get('aliases')->addError(new FormError($this->translator->trans('mailbox.invalid_aliases', [
                    '%aliases%' => implode(', ', $invalidAliases),
                ])));
            }

            if ([] !== $blockedFolders) {
                $hasCustomErrors = true;
                $form->get('folders')->addError(new FormError($this->translator->trans('mailbox.non_empty_folders', [
                    '%folders%' => implode(', ', $blockedFolders),
                ])));
            }

            if ([] !== $duplicateFunctions) {
                $hasCustomErrors = true;
                $form->get('folders')->addError(new FormError($this->translator->trans('mailbox.duplicate_folder_functions', [
                    '%functions%' => implode(', ', array_map(fn (string $key): string => $this->translator->trans($key), $duplicateFunctions)),
                ])));
            }

            if ([] !== $missingFunctions) {
                $hasCustomErrors = true;
                $form->get('folders')->addError(new FormError($this->translator->trans('mailbox.missing_folder_functions', [
                    '%functions%' => implode(', ', array_map(fn (string $key): string => $this->translator->trans($key), $missingFunctions)),
                ])));
            }

            if (!$hasCustomErrors) {
                $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
                $mailboxService->save($mailbox, $aliases, $folders);

                return $this->redirectToRoute('mailbox_index', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('mailbox/_modal_form.html.twig', [
            'form' => $form->createView(),
            'title' => $this->translator->trans('mailbox.edit'),
        ]);
    }

    #[Route('/{id}', name: 'mailbox_delete', methods: ['POST'])]
    public function delete(Mailbox $mailbox, Request $request, MailboxService $mailboxService): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null !== $token && $this->isCsrfTokenValid('delete_mailbox_'.$mailbox->getId(), $token)) {
            $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
            $mailboxService->delete($mailbox);
            $this->addFlash('success', $this->translator->trans('mailbox.deleted'));
        } else {
            $this->addFlash('danger', $this->translator->trans('common.invalid_token'));
        }

        return $this->redirectToRoute('mailbox_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    /**
     * @template TData
     *
     * @param FormInterface<TData> $form
     */
    private function getTextareaValue(FormInterface $form, string $field): ?string
    {
        $value = $form->get($field)->getData();
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}

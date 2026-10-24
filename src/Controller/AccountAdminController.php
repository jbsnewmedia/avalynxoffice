<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Form\AccountType;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;
use AvalynxOffice\Repository\AccountRepository;
use AvalynxOffice\Repository\ConnectorRepository;
use AvalynxOffice\Service\AccountService;
use AvalynxOffice\Util\AvalynxFormResponse;
use AvalynxOffice\Util\FormInvalidMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/accounts')]
#[IsGranted('ROLE_ADMIN')]
class AccountAdminController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    #[Route('', name: 'account_index', methods: ['GET'])]
    public function index(AccountRepository $repo): Response
    {
        $items = $repo->findBy([], ['name' => 'ASC']);

        return $this->render('account/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/view', name: 'account_structure', methods: ['GET'])]
    public function structure(AccountRepository $repo): Response
    {
        $items = $repo->findBy([], ['name' => 'ASC']);

        return $this->render('account/structure.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/new', name: 'account_new', methods: ['GET', 'POST'])]
    public function new(Request $request, AccountService $accountService, ConnectorRepository $connectorRepository): Response
    {
        $account = new Account();
        $connectorOptions = $this->connectorOptions($connectorRepository);
        $form = $this->createForm(AccountType::class, $account, [
            'action' => $this->generateUrl('account_new'),
            'aliases_data' => $accountService->formatAliases($account),
            'folders_data' => $accountService->formatFolders($account),
            'users_data' => [],
            'connector_options' => $connectorOptions,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $aliases = $accountService->normalizeAliases($this->getTextareaValue($form, 'aliases'));
            $folders = $accountService->normalizeFolders($this->getTextareaValue($form, 'folders'));
            $users = $this->getUsersValue($form);
            $invalidAliases = $accountService->findInvalidAliases($aliases);
            $conflictingAliases = $accountService->findConflictingAliases($account, $aliases);
            $duplicateFunctions = $accountService->findDuplicateFolderFunctions($folders);
            $missingFunctions = $accountService->findMissingSystemFolderFunctions($folders);
            $hasCustomErrors = false;

            if ([] !== $invalidAliases) {
                $hasCustomErrors = true;
                $form->get('aliases')->addError(new FormError($this->translator->trans('account.invalid_aliases', [
                    '%aliases%' => implode(', ', $invalidAliases),
                ])));
            }

            if ([] !== $conflictingAliases) {
                $hasCustomErrors = true;
                $form->get('aliases')->addError(new FormError($this->translator->trans('account.conflicting_aliases', [
                    '%aliases%' => implode(', ', $conflictingAliases),
                ])));
            }

            if ([] !== $duplicateFunctions) {
                $hasCustomErrors = true;
                $form->get('folders')->addError(new FormError($this->translator->trans('account.duplicate_folder_functions', [
                    '%functions%' => implode(', ', array_map(fn (string $key): string => $this->translator->trans($key), $duplicateFunctions)),
                ])));
            }

            if ([] !== $missingFunctions) {
                $hasCustomErrors = true;
                $form->get('folders')->addError(new FormError($this->translator->trans('account.missing_folder_functions', [
                    '%functions%' => implode(', ', array_map(fn (string $key): string => $this->translator->trans($key), $missingFunctions)),
                ])));
            }

            if (!$hasCustomErrors) {
                $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
                $accountService->save($account, $aliases, $folders, $users, true);

                return AvalynxFormResponse::success($this->generateUrl('account_index'));
            }
        }

        if ($request->isMethod('POST')) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        return $this->render('account/form.html.twig', [
            'form' => $form->createView(),
            'title' => $this->translator->trans('account.new'),
            'connectorOptions' => $connectorOptions,
        ]);
    }

    #[Route('/{id}/edit', name: 'account_edit', methods: ['GET', 'POST'])]
    public function edit(Account $account, Request $request, AccountService $accountService, ConnectorRepository $connectorRepository): Response
    {
        $connectorOptions = $this->connectorOptions($connectorRepository);
        $form = $this->createForm(AccountType::class, $account, [
            'action' => $this->generateUrl('account_edit', ['id' => $account->getId()]),
            'aliases_data' => $accountService->formatAliases($account),
            'folders_data' => $accountService->formatFolders($account),
            'users_data' => $account->getUsers()->toArray(),
            'connector_options' => $connectorOptions,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $aliases = $accountService->normalizeAliases($this->getTextareaValue($form, 'aliases'));
            $folders = $accountService->normalizeFolders($this->getTextareaValue($form, 'folders'));
            $users = $this->getUsersValue($form);
            $invalidAliases = $accountService->findInvalidAliases($aliases);
            $conflictingAliases = $accountService->findConflictingAliases($account, $aliases);
            $blockedFolders = $accountService->findNonRemovableFolders($account, $folders);
            $duplicateFunctions = $accountService->findDuplicateFolderFunctions($folders);
            $missingFunctions = $accountService->findMissingSystemFolderFunctions($folders);
            $hasCustomErrors = false;

            if ([] !== $invalidAliases) {
                $hasCustomErrors = true;
                $form->get('aliases')->addError(new FormError($this->translator->trans('account.invalid_aliases', [
                    '%aliases%' => implode(', ', $invalidAliases),
                ])));
            }

            if ([] !== $conflictingAliases) {
                $hasCustomErrors = true;
                $form->get('aliases')->addError(new FormError($this->translator->trans('account.conflicting_aliases', [
                    '%aliases%' => implode(', ', $conflictingAliases),
                ])));
            }

            if ([] !== $blockedFolders) {
                $hasCustomErrors = true;
                $form->get('folders')->addError(new FormError($this->translator->trans('account.non_empty_folders', [
                    '%folders%' => implode(', ', $blockedFolders),
                ])));
            }

            if ([] !== $duplicateFunctions) {
                $hasCustomErrors = true;
                $form->get('folders')->addError(new FormError($this->translator->trans('account.duplicate_folder_functions', [
                    '%functions%' => implode(', ', array_map(fn (string $key): string => $this->translator->trans($key), $duplicateFunctions)),
                ])));
            }

            if ([] !== $missingFunctions) {
                $hasCustomErrors = true;
                $form->get('folders')->addError(new FormError($this->translator->trans('account.missing_folder_functions', [
                    '%functions%' => implode(', ', array_map(fn (string $key): string => $this->translator->trans($key), $missingFunctions)),
                ])));
            }

            if (!$hasCustomErrors) {
                $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
                $accountService->save($account, $aliases, $folders, $users);

                return AvalynxFormResponse::success($this->generateUrl('account_index'));
            }
        }

        if ($request->isMethod('POST')) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        return $this->render('account/form.html.twig', [
            'form' => $form->createView(),
            'title' => $this->translator->trans('account.edit'),
            'connectorOptions' => $connectorOptions,
        ]);
    }

    #[Route('/{id}', name: 'account_delete', methods: ['POST'])]
    public function delete(Account $account, Request $request, AccountService $accountService): Response
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null !== $token && $this->isCsrfTokenValid('delete_account_'.$account->getId(), $token)) {
            $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
            $accountService->delete($account);
            $this->addFlash('success', $this->translator->trans('account.deleted'));
        } else {
            $this->addFlash('danger', $this->translator->trans('common.invalid_token'));
        }

        return $this->redirectToRoute('account_index', [], Response::HTTP_SEE_OTHER);
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

    /**
     * @template TData
     *
     * @param FormInterface<TData> $form
     *
     * @return list<User>
     */
    private function getUsersValue(FormInterface $form): array
    {
        $users = $form->get('users')->getData();
        if (!is_iterable($users)) {
            return [];
        }

        $list = [];
        foreach ($users as $user) {
            if ($user instanceof User) {
                $list[] = $user;
            }
        }

        return $list;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function connectorOptions(ConnectorRepository $connectorRepository): array
    {
        $options = [];
        foreach ($connectorRepository->findBy([], ['name' => 'ASC']) as $connector) {
            $options[] = [
                'value' => (string) $connector->getId(),
                'label' => sprintf('%s (%s)', $connector->getName(), $connector->getDomain()),
            ];
        }

        return $options;
    }
}

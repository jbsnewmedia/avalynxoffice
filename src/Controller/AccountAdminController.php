<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAlias;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Account administration (create, edit, delete) backed by an
 * AvalynxDataTable with modal forms, plus the account structure overview.
 */
#[Route('/admin/accounts')]
#[IsGranted('ROLE_ADMIN')]
class AccountAdminController extends AbstractController
{
    /**
     * Columns the admin table may be sorted by, mapped to their query
     * builder field names. Everything else from the client is ignored.
     *
     * @var array<string, string>
     */
    private const array SORTABLE_COLUMNS = [
        'name' => 'a.name',
        'email' => 'a.email',
    ];

    private const array PER_PAGE_CHOICES = [10, 25, 50, 100];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly AccountRepository $accountRepository,
    ) {
    }

    #[Route('', name: 'account_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('account/index.html.twig', [
            'readOnly' => $this->licenseEnforcement->isReadOnly(),
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

    #[Route('/data', name: 'account_data', methods: ['POST'])]
    public function data(Request $request): JsonResponse
    {
        $tokenRaw = $request->request->get('_token') ?? $request->headers->get('X-CSRF-Token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null === $token || !$this->isCsrfTokenValid('datatable', $token)) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('common.invalid_token')], Response::HTTP_FORBIDDEN);
        }

        $search = trim((string) $request->request->get('search', ''));
        $page = max(1, (int) $request->request->get('page', 1));
        $perPage = (int) $request->request->get('perpage', self::PER_PAGE_CHOICES[0]);
        if (!in_array($perPage, self::PER_PAGE_CHOICES, true)) {
            $perPage = self::PER_PAGE_CHOICES[0];
        }
        $sorting = $this->parseSorting((string) $request->request->get('sorting', ''));

        $total = $this->accountRepository->countAccountsForAdminList('');
        $filtered = $this->accountRepository->countAccountsForAdminList($search);
        $offset = ($page - 1) * $perPage;
        $accounts = $this->accountRepository->findAccountsForAdminList($search, $this->toOrderBy($sorting), $offset, $perPage);

        $rows = [];
        foreach ($accounts as $account) {
            $rows[] = [
                'data' => [
                    'id' => $account->getId()?->toRfc4122(),
                    'name' => htmlspecialchars($account->getName(), ENT_QUOTES, 'UTF-8'),
                    'email' => htmlspecialchars($account->getEmail(), ENT_QUOTES, 'UTF-8'),
                    'users' => $this->renderUsers($account),
                    'aliases' => $this->renderAliases($account),
                    'folders' => $this->renderFolders($account),
                    'options' => $this->renderOptions($account),
                ],
                'class' => '',
            ];
        }

        return new JsonResponse([
            'head' => ['columns' => [
                ['name' => $this->translator->trans('account.name'), 'sortable' => true, 'id' => 'name'],
                ['name' => $this->translator->trans('account.email'), 'sortable' => true, 'id' => 'email'],
                ['name' => $this->translator->trans('account.users'), 'raw' => true, 'id' => 'users'],
                ['name' => $this->translator->trans('account.aliases'), 'raw' => true, 'id' => 'aliases'],
                ['name' => $this->translator->trans('account.folders'), 'raw' => true, 'id' => 'folders'],
                ['name' => $this->translator->trans('account.actions'), 'raw' => true, 'id' => 'options', 'class' => 'avalynx-datatable-options', 'fixed' => 'end'],
            ]],
            'data' => $rows,
            'count' => [
                // "all" is read by avalynx-datatable 1.x, "total" is kept for
                // the documented JSON structure.
                'total' => $total,
                'all' => $total,
                'filtered' => $filtered,
                'start' => 0 === $filtered ? 0 : $offset + 1,
                'end' => min($offset + $perPage, $filtered),
                'perpage' => $perPage,
                'page' => $page,
            ],
            'sorting' => $sorting,
            'search' => ['value' => $search],
        ]);
    }

    #[Route('/new', name: 'account_new', methods: ['GET'])]
    public function newModal(Request $request, AccountService $accountService, ConnectorRepository $connectorRepository): Response
    {
        $this->requireXmlHttpRequest($request);

        $account = new Account();
        $form = $this->createAccountForm($account, $accountService, $connectorRepository, false);

        return $this->render('account/_modal_form.html.twig', [
            'form' => $form,
            'connectorOptions' => $this->connectorOptions($connectorRepository),
        ]);
    }

    #[Route('/new', name: 'account_create', methods: ['POST'])]
    public function create(Request $request, AccountService $accountService, ConnectorRepository $connectorRepository): JsonResponse
    {
        $this->requireXmlHttpRequest($request);

        $account = new Account();
        $form = $this->createAccountForm($account, $accountService, $connectorRepository, false);
        $form->handleRequest($request);

        $errorResponse = $this->handleAccountForm($form, $account, $accountService, isNew: true);
        if ($errorResponse instanceof JsonResponse) {
            return $errorResponse;
        }

        return AvalynxFormResponse::success($this->generateUrl('account_index'));
    }

    #[Route('/{id}/edit', name: 'account_edit', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function editModal(Account $account, Request $request, AccountService $accountService, ConnectorRepository $connectorRepository): Response
    {
        $this->requireXmlHttpRequest($request);

        $form = $this->createAccountForm($account, $accountService, $connectorRepository, true);

        return $this->render('account/_modal_form.html.twig', [
            'form' => $form,
            'connectorOptions' => $this->connectorOptions($connectorRepository),
        ]);
    }

    #[Route('/{id}/edit', name: 'account_update', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function update(Account $account, Request $request, AccountService $accountService, ConnectorRepository $connectorRepository): JsonResponse
    {
        $this->requireXmlHttpRequest($request);

        $form = $this->createAccountForm($account, $accountService, $connectorRepository, true);
        $form->handleRequest($request);

        $errorResponse = $this->handleAccountForm($form, $account, $accountService, isNew: false);
        if ($errorResponse instanceof JsonResponse) {
            return $errorResponse;
        }

        return AvalynxFormResponse::success($this->generateUrl('account_index'));
    }

    #[Route('/{id}', name: 'account_delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function delete(Account $account, Request $request, AccountService $accountService): Response
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null !== $token && $this->isCsrfTokenValid('delete_account_'.$account->getId()?->toRfc4122(), $token)) {
            $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
            $accountService->delete($account);
            $this->addFlash('success', $this->translator->trans('account.deleted'));
        } else {
            $this->addFlash('danger', $this->translator->trans('common.invalid_token'));
        }

        return $this->redirectToRoute('account_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return FormInterface<Account>
     */
    private function createAccountForm(Account $account, AccountService $accountService, ConnectorRepository $connectorRepository, bool $isEdit): FormInterface
    {
        $action = $isEdit
            ? $this->generateUrl('account_update', ['id' => $account->getId()?->toRfc4122()])
            : $this->generateUrl('account_create');

        return $this->createForm(AccountType::class, $account, [
            'action' => $action,
            'aliases_data' => $accountService->formatAliases($account),
            'folders_data' => $accountService->formatFolders($account),
            'users_data' => $account->getUsers()->toArray(),
            'connector_options' => $this->connectorOptions($connectorRepository),
        ]);
    }

    /**
     * Validates and saves the account form. Returns an invalid response for
     * a rejected submission or null when the account was saved.
     *
     * @param FormInterface<Account> $form
     */
    private function handleAccountForm(FormInterface $form, Account $account, AccountService $accountService, bool $isNew): ?JsonResponse
    {
        if (!$form->isSubmitted() || !$form->isValid()) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $aliases = $accountService->normalizeAliases($this->getTextareaValue($form, 'aliases'));
        $folders = $accountService->normalizeFolders($this->getTextareaValue($form, 'folders'));
        $users = $this->getUsersValue($form);
        $invalidAliases = $accountService->findInvalidAliases($aliases);
        $conflictingAliases = $accountService->findConflictingAliases($account, $aliases);
        $blockedFolders = $isNew ? [] : $accountService->findNonRemovableFolders($account, $folders);
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

        if ($hasCustomErrors) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
        $accountService->save($account, $aliases, $folders, $users, $isNew);

        return null;
    }

    /**
     * Validates the client sorting JSON and returns it keyed by column id
     * (the datatable echoes this back into its own state, so the keys must
     * match the column ids).
     *
     * @return array<string, string> column id => direction
     */
    private function parseSorting(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['name' => 'asc'];
        }

        $sorting = [];
        foreach ($decoded as $column => $direction) {
            if (!is_string($column) || !isset(self::SORTABLE_COLUMNS[$column])) {
                continue;
            }

            $sorting[$column] = is_string($direction) && 'desc' === strtolower($direction) ? 'desc' : 'asc';
        }

        return [] === $sorting ? ['name' => 'asc'] : $sorting;
    }

    /**
     * Translates the validated column sorting into the order-by map for the
     * query builder.
     *
     * @param array<string, string> $sorting column id => direction
     *
     * @return array<string, string> query field => direction
     */
    private function toOrderBy(array $sorting): array
    {
        $orderBy = [];
        foreach ($sorting as $column => $direction) {
            $orderBy[self::SORTABLE_COLUMNS[$column]] = $direction;
        }

        return $orderBy;
    }

    private function renderUsers(Account $account): string
    {
        $users = $account->getUsers();
        if ([] === $users->toArray()) {
            return '<span class="text-body-secondary small">'.htmlspecialchars($this->translator->trans('account.no_users'), ENT_QUOTES, 'UTF-8').'</span>';
        }

        $badges = [];
        foreach ($users as $user) {
            $badges[] = '<span class="badge bg-secondary-subtle text-body">'.htmlspecialchars($user->getEmail(), ENT_QUOTES, 'UTF-8').'</span>';
        }

        return '<div class="d-flex flex-wrap gap-1">'.implode('', $badges).'</div>';
    }

    private function renderAliases(Account $account): string
    {
        $aliases = $account->getAliases();
        if ($aliases->isEmpty()) {
            return '<span class="text-body-secondary small">'.htmlspecialchars($this->translator->trans('account.no_addresses'), ENT_QUOTES, 'UTF-8').'</span>';
        }

        $badges = [];
        foreach ($aliases as $alias) {
            \assert($alias instanceof AccountAlias);
            $label = htmlspecialchars($alias->getLocalPart().'@'.$alias->getConnector()?->getDomain(), ENT_QUOTES, 'UTF-8');
            $badges[] = '<span class="badge bg-secondary-subtle text-body">'.$label.'</span>';
        }

        return '<div class="d-flex flex-wrap gap-1">'.implode('', $badges).'</div>';
    }

    private function renderFolders(Account $account): string
    {
        $folders = $account->getFolders();
        if ($folders->isEmpty()) {
            return '<span class="text-body-secondary small">'.htmlspecialchars($this->translator->trans('folder.none'), ENT_QUOTES, 'UTF-8').'</span>';
        }

        $badges = [];
        foreach ($folders as $folder) {
            \assert($folder instanceof Folder);
            $functions = [];
            foreach ($folder->getFunctions() as $function) {
                $key = 'folder.function.'.($function instanceof FolderFunction ? $function->value : (string) $function);
                $functions[] = '<span class="badge bg-secondary-subtle text-body ms-1">'.htmlspecialchars($this->translator->trans($key), ENT_QUOTES, 'UTF-8').'</span>';
            }

            $badges[] = '<span class="badge bg-light text-body border">'
                .htmlspecialchars($folder->getName(), ENT_QUOTES, 'UTF-8')
                .([] === $functions
                    ? '<span class="badge bg-secondary-subtle text-body ms-1">'.htmlspecialchars($this->translator->trans('folder.function.none'), ENT_QUOTES, 'UTF-8').'</span>'
                    : implode('', $functions))
                .'</span>';
        }

        return '<div class="d-flex flex-wrap gap-1">'.implode('', $badges).'</div>';
    }

    private function renderOptions(Account $account): string
    {
        $accountId = $account->getId()?->toRfc4122();
        $readOnly = $this->licenseEnforcement->isReadOnly();
        $editLabel = htmlspecialchars($this->translator->trans('account.edit'), ENT_QUOTES, 'UTF-8');
        $deleteLabel = htmlspecialchars($this->translator->trans('email.delete'), ENT_QUOTES, 'UTF-8');

        $editUrl = $this->generateUrl('account_edit', ['id' => $accountId]);
        $html = '<a href="'.htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8').'"'
            .' class="btn btn-sm btn-primary action-btn-modal me-1"'
            .' data-title="'.$editLabel.'" data-modal-size="modal-xl"'
            .' title="'.$editLabel.'" aria-label="'.$editLabel.'">'
            .'<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></a>';

        $deleteUrl = $this->generateUrl('account_delete', ['id' => $accountId]);
        $confirm = htmlspecialchars($this->translator->trans('account.confirm_delete'), ENT_QUOTES, 'UTF-8');

        $html .= '<form method="post" action="'.htmlspecialchars($deleteUrl, ENT_QUOTES, 'UTF-8').'" class="d-inline">'
            .'<input type="hidden" name="_token" value="'
            .htmlspecialchars($this->csrfTokenManager->getToken('delete_account_'.$accountId)->getValue(), ENT_QUOTES, 'UTF-8').'">'
            .'<button type="submit" class="btn btn-sm btn-danger confirm-action"'
            .' data-confirm="'.$confirm.'" title="'.$deleteLabel.'" aria-label="'.$deleteLabel.'"'
            .' '.($readOnly ? 'disabled' : '').'>'
            .'<i class="fa-solid fa-trash" aria-hidden="true"></i></button></form>';

        return $html;
    }

    private function requireXmlHttpRequest(Request $request): void
    {
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedException();
        }
    }

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

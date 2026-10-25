<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Form\UserAdminType;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Service\UserService;
use AvalynxOffice\Util\AvalynxFormResponse;
use AvalynxOffice\Util\FormInvalidMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Complete user administration (create, edit, delete) backed by an
 * AvalynxDataTable with modal forms.
 */
#[IsGranted('ROLE_ADMIN')]
final class UserAdminController extends AbstractController
{
    /**
     * Columns the admin table may be sorted by, mapped to their query
     * builder field names. Everything else from the client is ignored.
     *
     * @var array<string, string>
     */
    private const array SORTABLE_COLUMNS = [
        'email' => 'u.email',
        'active' => 'u.active',
        'lastAuthAt' => 'u.lastAuthAt',
    ];
    private const array PER_PAGE_CHOICES = [10, 25, 50, 100];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
        private readonly UserService $userService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/admin/users', name: 'license_admin_users', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/users/index.html.twig', [
            'readOnly' => $this->licenseEnforcement->isReadOnly(),
        ]);
    }

    #[Route(path: '/admin/users/data', name: 'admin_users_data', methods: ['POST'])]
    public function data(Request $request): JsonResponse
    {
        $search = trim((string) $request->request->get('search', ''));
        $page = max(1, (int) $request->request->get('page', 1));
        $perPage = (int) $request->request->get('perpage', self::PER_PAGE_CHOICES[0]);
        if (!in_array($perPage, self::PER_PAGE_CHOICES, true)) {
            $perPage = self::PER_PAGE_CHOICES[0];
        }
        $sorting = $this->parseSorting((string) $request->request->get('sorting', ''));

        $userRepository = $this->entityManager->getRepository(User::class);
        $total = $userRepository->countUsersForAdminList('');
        $filtered = $userRepository->countUsersForAdminList($search);
        $offset = ($page - 1) * $perPage;
        $users = $userRepository->findUsersForAdminList($search, $this->toOrderBy($sorting), $offset, $perPage);

        $currentUser = $this->getUser();
        \assert($currentUser instanceof User);
        $readOnly = $this->licenseEnforcement->isReadOnly();

        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                'data' => [
                    'id' => $user->getId()?->toRfc4122(),
                    'email' => htmlspecialchars($user->getEmail(), ENT_QUOTES, 'UTF-8'),
                    'roles' => $this->renderRoles($user),
                    'active' => $this->renderState($user),
                    'lastAuthAt' => $user->getLastAuthAt()?->format('Y-m-d H:i') ?? '<span class="text-body-secondary">—</span>',
                    'options' => $this->renderOptions($user, $currentUser, $readOnly),
                ],
                'class' => $user->isActive() ? '' : 'table-secondary',
            ];
        }

        return new JsonResponse([
            'head' => ['columns' => [
                ['name' => $this->translator->trans('profile.email'), 'sortable' => true, 'id' => 'email'],
                ['name' => $this->translator->trans('license.users.roles'), 'raw' => true, 'id' => 'roles'],
                ['name' => $this->translator->trans('license.users.state'), 'raw' => true, 'sortable' => true, 'id' => 'active'],
                ['name' => $this->translator->trans('license.users.last_auth'), 'raw' => true, 'sortable' => true, 'id' => 'lastAuthAt'],
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

    #[Route(path: '/admin/users/new', name: 'admin_users_new', methods: ['GET'])]
    public function newModal(Request $request): Response
    {
        $this->requireXmlHttpRequest($request);

        $form = $this->createForm(UserAdminType::class, new User(), [
            'action' => $this->generateUrl('admin_users_new'),
            'is_edit' => false,
        ]);

        return $this->render('admin/users/_modal_form.html.twig', [
            'form' => $form,
            'title' => $this->translator->trans('admin.users.new'),
            'is_edit' => false,
        ]);
    }

    #[Route(path: '/admin/users/new', name: 'admin_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->requireXmlHttpRequest($request);

        $user = new User();
        $form = $this->createForm(UserAdminType::class, $user, [
            'action' => $this->generateUrl('admin_users_new'),
            'is_edit' => false,
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $emailRaw = $form->get('email')->getData();
        $email = strtolower(trim(is_string($emailRaw) ? $emailRaw : ''));
        \assert('' !== $email);
        if (null !== $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])) {
            $form->get('email')->addError(new FormError($this->translator->trans('admin.users.email_taken')));

            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $passwordRaw = $form->get('password')->getData();
        $password = is_string($passwordRaw) ? $passwordRaw : '';
        $user->setEmail($email);
        $user->setRoles($this->wantedRoles((bool) $form->get('admin')->getData()));
        $user->setActive((bool) $form->get('active')->getData());
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        // Seat accounting and the user insert must share one transaction so
        // that concurrent activations are serialized by the license state
        // row lock.
        $this->entityManager->wrapInTransaction(function () use ($user): void {
            $this->licenseEnforcement->assertUserSeatAvailable();
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        });

        return AvalynxFormResponse::success($this->generateUrl('license_admin_users'), [
            'message' => $this->translator->trans('admin.users.created'),
        ]);
    }

    #[Route(path: '/admin/users/{id}/edit', name: 'admin_users_edit', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function editModal(string $id, Request $request): Response
    {
        $this->requireXmlHttpRequest($request);

        $user = $this->findUser($id);

        $form = $this->createForm(UserAdminType::class, $user, [
            'action' => $this->generateUrl('admin_users_edit', ['id' => $id]),
            'is_edit' => true,
        ]);

        // Unmapped fields do not read from the entity: prefill them here so
        // the edit modal shows the current values.
        $form->get('email')->setData($user->getEmail());
        $form->get('active')->setData($user->isActive());
        $form->get('admin')->setData(in_array('ROLE_ADMIN', $user->getRoles(), true));

        return $this->render('admin/users/_modal_form.html.twig', [
            'form' => $form,
            'title' => $this->translator->trans('admin.users.edit'),
            'is_edit' => true,
        ]);
    }

    #[Route(path: '/admin/users/{id}/edit', name: 'admin_users_update', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->requireXmlHttpRequest($request);

        $user = $this->findUser($id);

        $form = $this->createForm(UserAdminType::class, $user, [
            'action' => $this->generateUrl('admin_users_edit', ['id' => $id]),
            'is_edit' => true,
        ]);

        $originalEmail = $user->getEmail();
        $hadAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);

        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $emailRaw = $form->get('email')->getData();
        $email = strtolower(trim(is_string($emailRaw) ? $emailRaw : ''));
        \assert('' !== $email);
        $passwordRaw = $form->get('password')->getData();
        $password = is_string($passwordRaw) ? $passwordRaw : '';
        $active = (bool) $form->get('active')->getData();
        $wantsAdmin = (bool) $form->get('admin')->getData();

        $emailChanged = $email !== $originalEmail;
        $passwordChanged = '' !== $password;
        $adminChanged = $wantsAdmin !== $hadAdmin;
        $deactivating = $user->isActive() && !$active;
        $activating = !$user->isActive() && $active;

        if (null !== $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])
            && $emailChanged) {
            $form->get('email')->addError(new FormError($this->translator->trans('admin.users.email_taken')));

            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        // In read-only mode deactivation stays possible (it is the documented
        // way to end the read-only state); every other change is blocked.
        if ($this->licenseEnforcement->isReadOnly()
            && !($deactivating && !$emailChanged && !$passwordChanged && !$adminChanged)) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.users.read_only_error'));
        }

        if ($deactivating && $this->userService->isLastActiveAdmin($user)) {
            $form->get('active')->addError(new FormError($this->translator->trans('admin.users.last_admin_error')));

            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        if ($emailChanged) {
            $user->setEmail($email);
        }

        if ($passwordChanged) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        }

        $user->setActive($active);

        if ($wantsAdmin && !$hadAdmin) {
            $user->setRoles($this->wantedRoles(true, $user->getRoles()));
        }

        if (!$wantsAdmin && $hadAdmin) {
            try {
                $this->userService->toggleAdmin($user);
            } catch (\DomainException) {
                $form->get('admin')->addError(new FormError($this->translator->trans('admin.users.last_admin_error')));

                return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
            }
        }

        // Activating an account consumes a seat: the check and the save must
        // share one transaction so that concurrent activations are serialized
        // by the license state row lock.
        $this->entityManager->wrapInTransaction(function () use ($activating): void {
            if ($activating) {
                $this->licenseEnforcement->assertUserSeatAvailable();
            }

            $this->entityManager->flush();
        });

        return AvalynxFormResponse::success($this->generateUrl('license_admin_users'), [
            'message' => $this->translator->trans('admin.users.updated'),
        ]);
    }

    #[Route(path: '/admin/users/{id}/delete', name: 'admin_users_delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function delete(string $id, Request $request): Response
    {
        $user = $this->findUser($id);

        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null === $token || !$this->isCsrfTokenValid('admin_users_delete_'.$user->getId()?->toRfc4122(), $token)) {
            throw new AccessDeniedHttpException('Invalid user delete token.');
        }

        if ($this->licenseEnforcement->isReadOnly()) {
            throw new AccessDeniedHttpException('Cannot delete users while the installation is in read-only mode.');
        }

        $currentUser = $this->getUser();
        \assert($currentUser instanceof User);
        if ($currentUser->getUserIdentifier() === $user->getUserIdentifier()) {
            throw new AccessDeniedHttpException('The own account cannot be deleted.');
        }

        if ($this->userService->isLastActiveAdmin($user)) {
            throw new AccessDeniedHttpException('The last active administrator cannot be deleted.');
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $this->addFlash('success', 'admin.users.deleted');

        return $this->redirectToRoute('license_admin_users', [], Response::HTTP_SEE_OTHER);
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
            return ['email' => 'asc'];
        }

        $sorting = [];
        foreach ($decoded as $column => $direction) {
            if (!is_string($column) || !isset(self::SORTABLE_COLUMNS[$column])) {
                continue;
            }

            $sorting[$column] = is_string($direction) && 'desc' === strtolower($direction) ? 'desc' : 'asc';
        }

        return [] === $sorting ? ['email' => 'asc'] : $sorting;
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

    private function findUser(string $id): User
    {
        $user = $this->entityManager->find(User::class, $id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        return $user;
    }

    /**
     * @param array<string> $existing
     *
     * @return array<string>
     */
    private function wantedRoles(bool $admin, array $existing = []): array
    {
        $roles = [] === $existing ? ['ROLE_USER'] : $existing;

        if ($admin && !in_array('ROLE_ADMIN', $roles, true)) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }

    private function renderRoles(User $user): string
    {
        $badges = [];
        foreach ($user->getRoles() as $role) {
            $badges[] = '<span class="badge bg-secondary-subtle text-body">'.htmlspecialchars($this->roleLabel($role), ENT_QUOTES, 'UTF-8').'</span>';
        }

        return implode(' ', $badges);
    }

    private function renderState(User $user): string
    {
        if ($user->isActive()) {
            return '<span class="badge bg-success-subtle text-body"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>'
                .htmlspecialchars($this->translator->trans('license.users.active'), ENT_QUOTES, 'UTF-8').'</span>';
        }

        return '<span class="badge bg-secondary"><i class="fa-solid fa-ban me-1" aria-hidden="true"></i>'
            .htmlspecialchars($this->translator->trans('license.users.inactive'), ENT_QUOTES, 'UTF-8').'</span>';
    }

    private function renderOptions(User $user, User $currentUser, bool $readOnly): string
    {
        $userId = $user->getId()?->toRfc4122();
        $editLabel = htmlspecialchars($this->translator->trans('admin.users.edit'), ENT_QUOTES, 'UTF-8');
        $deleteLabel = htmlspecialchars($this->translator->trans('admin.users.delete'), ENT_QUOTES, 'UTF-8');

        $editUrl = $this->generateUrl('admin_users_edit', ['id' => $userId]);
        $html = '<a href="'.htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8').'"'
            .' class="btn btn-sm btn-primary action-btn-modal me-1"'
            .' data-title="'.$editLabel.'" data-modal-size="modal-lg"'
            .' title="'.$editLabel.'" aria-label="'.$editLabel.'">'
            .'<i class="fa-solid fa-pen" aria-hidden="true"></i></a>';

        $deleteUrl = $this->generateUrl('admin_users_delete', ['id' => $userId]);
        $deleteDisabled = $readOnly
            || $currentUser->getUserIdentifier() === $user->getUserIdentifier()
            || $this->userService->isLastActiveAdmin($user);
        $confirm = htmlspecialchars($this->translator->trans('admin.users.confirm_delete'), ENT_QUOTES, 'UTF-8');

        $html .= '<form method="post" action="'.htmlspecialchars($deleteUrl, ENT_QUOTES, 'UTF-8').'" class="d-inline">'
            .'<input type="hidden" name="_token" value="'
            .htmlspecialchars($this->csrfTokenManager->getToken('admin_users_delete_'.$userId)->getValue(), ENT_QUOTES, 'UTF-8').'">'
            .'<button type="submit" class="btn btn-sm btn-danger confirm-action"'
            .' data-confirm="'.$confirm.'" title="'.$deleteLabel.'" aria-label="'.$deleteLabel.'"'
            .' '.($deleteDisabled ? 'disabled' : '').'>'
            .'<i class="fa-solid fa-trash" aria-hidden="true"></i></button></form>';

        return $html;
    }

    /**
     * Localized role label with fallback to the raw role identifier (same
     * behaviour as the role_label Twig filter).
     */
    private function roleLabel(string $role): string
    {
        $key = 'role.'.strtolower(preg_replace('/^ROLE_/', '', $role) ?? $role);
        $translated = $this->translator->trans($key);

        return $translated === $key ? $role : $translated;
    }

    private function requireXmlHttpRequest(Request $request): void
    {
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedException();
        }
    }
}

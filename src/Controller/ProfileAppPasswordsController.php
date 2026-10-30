<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Service\Login\AppPasswordService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Device/app passwords for Exchange ActiveSync clients: generate a new
 * password (shown once) and revoke existing ones.
 */
#[AsController]
#[IsGranted('ROLE_USER')]
final class ProfileAppPasswordsController extends AbstractController
{
    /** Session attribute holding the freshly generated password for one display. */
    private const string SESSION_NEW_PASSWORD = 'app_password_new';

    public function __construct(
        private readonly AppPasswordService $appPasswordService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/profile/app-passwords', name: 'app_profile_app_passwords', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $session = $request->getSession();
        $newPassword = $session->get(self::SESSION_NEW_PASSWORD);
        $session->remove(self::SESSION_NEW_PASSWORD);

        return $this->render('profile/app_passwords.html.twig', [
            'passwords' => $this->appPasswordService->activeFor($user),
            'newPassword' => \is_array($newPassword) ? $newPassword : null,
            'canCreate' => $this->appPasswordService->canCreate($user),
            'maxActive' => AppPasswordService::MAX_ACTIVE,
            'maxLabelLength' => AppPasswordService::MAX_LABEL_LENGTH,
        ]);
    }

    #[Route('/profile/app-passwords', name: 'app_profile_app_passwords_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $submittedToken = (string) $request->request->get('_token', '');

        if (!$this->isCsrfTokenValid('app_password_create', $submittedToken)) {
            $this->addFlash('danger', $this->translator->trans('common.invalid_token'));

            return $this->redirectToRoute('app_profile_app_passwords');
        }

        $label = trim((string) $request->request->get('label', ''));

        if ('' === $label || mb_strlen($label) > AppPasswordService::MAX_LABEL_LENGTH) {
            $this->addFlash('danger', $this->translator->trans('profile.app_passwords.label_invalid'));

            return $this->redirectToRoute('app_profile_app_passwords');
        }

        if (!$this->appPasswordService->canCreate($user)) {
            $this->addFlash('danger', $this->translator->trans('profile.app_passwords.limit_reached', ['%max%' => AppPasswordService::MAX_ACTIVE]));

            return $this->redirectToRoute('app_profile_app_passwords');
        }

        [$row, $plain] = $this->appPasswordService->create($user, $label);

        $request->getSession()->set(self::SESSION_NEW_PASSWORD, [
            'label' => $row->getLabel(),
            'password' => $plain,
        ]);

        return $this->redirectToRoute('app_profile_app_passwords');
    }

    #[Route('/profile/app-passwords/{id}/revoke', name: 'app_profile_app_passwords_revoke', methods: ['POST'])]
    public function revoke(string $id, Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $submittedToken = (string) $request->request->get('_token', '');

        if (!$this->isCsrfTokenValid('app_password_revoke_'.$id, $submittedToken)) {
            $this->addFlash('danger', $this->translator->trans('common.invalid_token'));

            return $this->redirectToRoute('app_profile_app_passwords');
        }

        $revoked = $this->appPasswordService->revoke($user, $id);
        $this->addFlash($revoked ? 'success' : 'warning', $revoked ? $this->translator->trans('profile.app_passwords.revoked') : $this->translator->trans('common.invalid_token'));

        return $this->redirectToRoute('app_profile_app_passwords');
    }
}

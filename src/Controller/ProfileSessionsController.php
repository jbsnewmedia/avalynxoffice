<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Service\Login\LoginSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Overview of the user's concurrent logins with the option to end
 * individual sessions (kick) or all other sessions at once.
 */
#[AsController]
#[IsGranted('ROLE_USER')]
final class ProfileSessionsController extends AbstractController
{
    public function __construct(
        private readonly LoginSessionService $loginSessionService,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route('/profile/sessions', name: 'app_profile_sessions')]
    public function index(): Response
    {
        $user = $this->getUser();
        \assert($user instanceof \AvalynxOffice\Entity\User);

        // Rows store the hashed token, so the template compares against the
        // hash of the plaintext token carried by the current session.
        $currentToken = $this->currentToken();

        return $this->render('profile/sessions.html.twig', [
            'sessions' => $this->loginSessionService->activeFor($user),
            'currentToken' => null === $currentToken ? null : LoginSessionService::hashToken($currentToken),
        ]);
    }

    #[Route('/profile/sessions/{id}/revoke', name: 'app_profile_sessions_revoke', methods: ['POST'])]
    public function revoke(string $id, Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof \AvalynxOffice\Entity\User);

        $submittedToken = (string) $request->request->get('_token', '');

        if (!$this->isCsrfTokenValid('session_revoke_'.$id, $submittedToken)) {
            $this->addFlash('danger', $this->translator->trans('common.invalid_token'));

            return $this->redirectToRoute('app_profile_sessions');
        }

        $revoked = $this->loginSessionService->revoke($user, $id);
        $this->addFlash($revoked ? 'success' : 'warning', $revoked ? $this->translator->trans('profile.sessions.revoked') : $this->translator->trans('common.invalid_token'));

        return $this->redirectToRoute('app_profile_sessions');
    }

    #[Route('/profile/sessions/revoke-others', name: 'app_profile_sessions_revoke_others', methods: ['POST'])]
    public function revokeOthers(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof \AvalynxOffice\Entity\User);

        $submittedToken = (string) $request->request->get('_token', '');

        if (!$this->isCsrfTokenValid('session_revoke_others', $submittedToken)) {
            $this->addFlash('danger', $this->translator->trans('common.invalid_token'));

            return $this->redirectToRoute('app_profile_sessions');
        }

        $currentToken = $this->currentToken() ?? '';
        $count = $this->loginSessionService->revokeOthers($user, $currentToken);
        $this->addFlash('success', $this->translator->trans('profile.sessions.others_revoked', ['%count%' => $count]));

        return $this->redirectToRoute('app_profile_sessions');
    }

    private function currentToken(): ?string
    {
        $token = $this->requestStack->getSession()->get(LoginSessionService::SESSION_ATTRIBUTE);

        return is_string($token) && '' !== $token ? $token : null;
    }
}

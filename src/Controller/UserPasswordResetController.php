<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\PasswordResetRequest;
use AvalynxOffice\Repository\PasswordResetRequestRepository;
use AvalynxOffice\Service\AdminPasswordResetService;
use AvalynxOffice\Util\AvalynxFormResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * User-facing flow of the administrator-assisted password reset. One page
 * renders the four states of the flow (token entry, token + countdown,
 * password entry, success) and polls the JSON status endpoint to switch
 * states when the administrator releases the request.
 */
final class UserPasswordResetController extends AbstractController
{
    public function __construct(
        private readonly AdminPasswordResetService $adminPasswordResetService,
        private readonly PasswordResetRequestRepository $passwordResetRequestRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/profile/password-reset/admin-flow/{token}', name: 'app_user_password_reset_flow', methods: ['GET'])]
    public function flow(string $token): Response
    {
        $request = $this->passwordResetRequestRepository->findByToken($token);
        $now = new \DateTimeImmutable();

        $state = 'unknown';

        if ($request instanceof PasswordResetRequest) {
            if ($this->adminPasswordResetService->isAwaitingUser($request, $now)) {
                $state = $request->getStatus();
            } else {
                $state = PasswordResetRequest::STATUS_EXPIRED;
            }
        }

        return $this->render('profile/password_reset_flow.html.twig', [
            'token' => $token,
            'state' => $state,
            'statusUrl' => $this->generateUrl('app_user_password_reset_status', ['token' => $token]),
            'submitUrl' => $this->generateUrl('app_user_password_reset_submit', ['token' => $token]),
        ]);
    }

    #[Route('/profile/password-reset/admin-flow/{token}/status', name: 'app_user_password_reset_status', methods: ['GET'])]
    public function status(string $token): JsonResponse
    {
        $request = $this->passwordResetRequestRepository->findByToken($token);
        $now = new \DateTimeImmutable();

        $state = 'unknown';
        $secondsLeft = 0;

        if ($request instanceof PasswordResetRequest) {
            if ($this->adminPasswordResetService->isAwaitingUser($request, $now)) {
                $state = $request->getStatus();

                $releaseExpiresAt = $request->getReleaseExpiresAt();
                $expiresAt = $request->getExpiresAt();

                if (null !== $releaseExpiresAt && PasswordResetRequest::STATUS_RELEASED === $request->getStatus()) {
                    $secondsLeft = max(0, $releaseExpiresAt->getTimestamp() - $now->getTimestamp());
                } elseif (null !== $expiresAt) {
                    $secondsLeft = max(0, $expiresAt->getTimestamp() - $now->getTimestamp());
                }
            } else {
                $state = PasswordResetRequest::STATUS_EXPIRED;
            }
        }

        return $this->json([
            'state' => $state,
            'secondsLeft' => $secondsLeft,
        ]);
    }

    #[Route('/profile/password-reset/admin-flow/{token}', name: 'app_user_password_reset_submit', methods: ['POST'])]
    public function submit(string $token, Request $request): JsonResponse
    {
        $tokenRaw = $request->request->get('_token');
        $csrfToken = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        if (null === $csrfToken || !$this->isCsrfTokenValid('user_password_reset_submit', $csrfToken)) {
            return AvalynxFormResponse::failure($this->translator->trans('password.reset.invalid_token'));
        }

        $passwordRaw = $request->request->get('password');
        $password = is_scalar($passwordRaw) ? (string) $passwordRaw : '';

        $passwordConfirmRaw = $request->request->get('password_confirm');
        $passwordConfirm = is_scalar($passwordConfirmRaw) ? (string) $passwordConfirmRaw : '';

        if ('' === $password) {
            return AvalynxFormResponse::invalid([
                'password' => $this->translator->trans('password.reset.empty_password'),
            ]);
        }

        if ($password !== $passwordConfirm) {
            return AvalynxFormResponse::invalid([
                'password_confirm' => $this->translator->trans('password.reset.password_mismatch'),
            ]);
        }

        $resetRequest = $this->passwordResetRequestRepository->findByToken($token);

        if (null === $resetRequest) {
            return AvalynxFormResponse::failure($this->translator->trans('password.reset.flow_invalid'));
        }

        try {
            $this->adminPasswordResetService->complete($resetRequest, $password);
        } catch (\DomainException) {
            return AvalynxFormResponse::failure($this->translator->trans('password.reset.flow_expired'));
        }

        $this->addFlash('success', 'password.reset.flow_success');

        return AvalynxFormResponse::success($this->generateUrl('app_login'));
    }
}

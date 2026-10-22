<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\RecoveryAddress;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\UserRepository;
use AvalynxOffice\Service\AdminPasswordResetService;
use AvalynxOffice\Service\UserService;
use AvalynxOffice\Util\AvalynxFormResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProfileController extends AbstractController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UriSigner $uriSigner,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
        private readonly AdminPasswordResetService $adminPasswordResetService,
    ) {
    }

    #[Route('/profile', name: 'app_profile')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('profile/index.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/profile/recovery/add', name: 'app_profile_recovery_add', methods: ['POST'])]
    public function addRecoveryAddress(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        if (null === $token || !$this->isCsrfTokenValid('add_recovery', $token)) {
            return AvalynxFormResponse::failure($this->translator->trans('profile.recovery.invalid_token'));
        }

        $emailRaw = $request->request->get('email');
        $email = is_scalar($emailRaw) ? trim((string) $emailRaw) : '';

        if ('' === $email) {
            return AvalynxFormResponse::invalid([
                'email' => $this->translator->trans('profile.recovery.email_required'),
            ]);
        }

        try {
            $this->userService->addRecoveryAddress($user, $email);
            $this->entityManager->flush();
            $this->addFlash('success', 'profile.recovery.added');
        } catch (\DomainException) {
            return AvalynxFormResponse::invalid([
                'email' => $this->translator->trans('profile.recovery.add_failed'),
            ]);
        }

        return AvalynxFormResponse::success($this->generateUrl('app_profile'));
    }

    #[Route('/profile/recovery/remove/{id}', name: 'app_profile_recovery_remove', methods: ['POST'])]
    public function removeRecoveryAddress(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $recoveryAddress = $this->entityManager->find(RecoveryAddress::class, $id);

        if (!$recoveryAddress instanceof RecoveryAddress || $recoveryAddress->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Recovery address not found.');
        }

        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        if (null === $token || !$this->isCsrfTokenValid('remove_recovery_'.$recoveryAddress->getId(), $token)) {
            $this->addFlash('danger', 'profile.recovery.invalid_token');

            return new RedirectResponse($this->generateUrl('app_profile'));
        }

        $this->userService->removeRecoveryAddress($user, $recoveryAddress);
        $this->entityManager->flush();
        $this->addFlash('success', 'profile.recovery.removed');

        return new RedirectResponse($this->generateUrl('app_profile'));
    }

    #[Route('/profile/password-reset', name: 'app_password_reset_request', methods: ['GET'])]
    public function passwordResetRequest(): Response
    {
        return $this->render('profile/password_reset_request.html.twig', [
            'code' => null,
            'sent' => false,
            'error' => null,
        ]);
    }

    /**
     * User-initiated two-key reset: the user generates token and release
     * number on this page and presents both to the administrator in person.
     * Logged-in users start the flow for their own account, anonymous users
     * have to provide their account email address.
     */
    #[Route('/profile/password-reset/code', name: 'app_password_reset_code_start', methods: ['POST'])]
    public function passwordResetCodeStart(Request $request): Response
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        if (null === $token || !$this->isCsrfTokenValid('password_reset_code', $token)) {
            $this->addFlash('danger', $this->translator->trans('password.reset.invalid_token'));

            return $this->redirectToRoute('app_password_reset_request');
        }

        $user = $this->getUser();
        $emailRaw = $request->request->get('email');
        $email = is_scalar($emailRaw) ? trim((string) $emailRaw) : '';

        $target = null;

        if ($user instanceof User) {
            $target = $user;
        } else {
            if ('' === $email) {
                $this->addFlash('danger', $this->translator->trans('password.reset.code_email_required'));

                return $this->redirectToRoute('app_password_reset_request');
            }

            $candidate = $this->userRepository->findOneBy(['email' => strtolower($email)]);

            if (null === $candidate || !$candidate->isActive()) {
                $this->addFlash('danger', $this->translator->trans('password.reset.code_user_not_found'));

                return $this->redirectToRoute('app_password_reset_request');
            }

            $target = $candidate;
        }

        $clientIp = $request->getClientIp() ?? 'unknown';
        \assert('' !== $clientIp);

        $result = $this->adminPasswordResetService->startUserRequest(
            $target,
            $clientIp,
        );

        // Render directly (no redirect): token and release number are shown
        // exactly once and must not get lost with the flash message.
        return $this->render('profile/password_reset_request.html.twig', [
            'code' => [
                'token' => $result['token'],
                'number' => $result['number'],
            ],
        ]);
    }

    #[Route('/profile/password-reset', name: 'app_password_reset_send', methods: ['POST'])]
    public function passwordResetSend(Request $request): Response
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        $sent = false;
        $error = null;

        if (null === $token || !$this->isCsrfTokenValid('password_reset_request', $token)) {
            $error = $this->translator->trans('password.reset.invalid_token');
        } else {
            $emailRaw = $request->request->get('email');
            $email = is_scalar($emailRaw) ? trim((string) $emailRaw) : '';

            if ('' === $email) {
                $error = $this->translator->trans('password.reset.email_required');
            } else {
                // The answer never reveals whether the account (or a recovery
                // address) exists — the same message is shown in every case.
                $this->userService->requestPasswordReset(
                    $email,
                    fn (User $candidate): string => $this->generateUrl(
                        'app_profile_password_reset_execute',
                        ['userId' => $candidate->getId()?->toRfc4122() ?? ''],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                );

                $sent = true;
            }
        }

        return $this->render('profile/password_reset_request.html.twig', [
            'code' => null,
            'sent' => $sent,
            'error' => $error,
        ]);
    }

    #[Route('/profile/password-reset/execute/{userId}', name: 'app_profile_password_reset_execute', methods: ['GET'])]
    public function executePasswordReset(string $userId): Response
    {
        if (!$this->isSignedRequestValid()) {
            throw $this->createNotFoundException('Invalid or expired reset link.');
        }

        if (null === $this->userRepository->find($userId)) {
            throw $this->createNotFoundException('User not found.');
        }

        return $this->renderResetForm($userId);
    }

    #[Route('/profile/password-reset/submit/{userId}', name: 'app_profile_password_reset_submit', methods: ['POST'])]
    public function submitPasswordReset(Request $request, string $userId): JsonResponse
    {
        if (!$this->isSignedRequestValid()) {
            return AvalynxFormResponse::failure($this->translator->trans('password.reset.link_invalid'));
        }

        $user = $this->userRepository->find($userId);

        if (null === $user) {
            throw $this->createNotFoundException('User not found.');
        }

        $passwordRaw = $request->request->get('password');
        $password = is_scalar($passwordRaw) ? (string) $passwordRaw : '';

        if ('' === $password) {
            return AvalynxFormResponse::invalid([
                'password' => $this->translator->trans('password.reset.empty_password'),
            ]);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->flush();

        $this->addFlash('success', 'password.reset.success');

        return AvalynxFormResponse::success($this->generateUrl('app_login'));
    }

    /**
     * The reset form posts to its own freshly signed URL: the signature of
     * the execute link does not cover the submit endpoint, so handing a
     * plain path to the template would fail verification on submit.
     */
    private function renderResetForm(string $userId): Response
    {
        $submitUrl = $this->uriSigner->sign(
            $this->generateUrl('app_profile_password_reset_submit', ['userId' => $userId], UrlGeneratorInterface::ABSOLUTE_URL),
            1800,
        );

        return $this->render('profile/password_reset.html.twig', [
            'userId' => $userId,
            'submitUrl' => $submitUrl,
        ]);
    }

    private function isSignedRequestValid(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        return $this->uriSigner->checkRequest($request);
    }
}

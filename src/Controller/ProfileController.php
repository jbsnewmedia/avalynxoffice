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
use AvalynxOffice\Service\Login\LoginSessionService;
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
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProfileController extends AbstractController
{
    /**
     * Session key for the one-time display of a freshly created
     * administrator reset code (flow token and release number).
     */
    private const string SESSION_KEY_CODE = 'password_reset_code_result';

    /**
     * Session key for the one-time display of the "link sent" confirmation.
     */
    private const string SESSION_KEY_SENT = 'password_reset_link_sent';

    public function __construct(
        private readonly UserService $userService,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UriSigner $uriSigner,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
        private readonly AdminPasswordResetService $adminPasswordResetService,
        private readonly LoginSessionService $loginSessionService,
        private readonly RateLimiterFactoryInterface $passwordResetLimiter,
    ) {
    }

    #[Route('/profile', name: 'app_profile')]
    public function index(): Response
    {
        return $this->render('profile/index.html.twig');
    }

    #[Route('/profile/account', name: 'app_profile_account')]
    public function account(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('profile/account.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/profile/recovery', name: 'app_profile_recovery')]
    public function recovery(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('profile/recovery.html.twig', [
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

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return AvalynxFormResponse::invalid([
                'email' => $this->translator->trans('profile.recovery.email_invalid'),
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

        return AvalynxFormResponse::success($this->generateUrl('app_profile_recovery'));
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

            return new RedirectResponse($this->generateUrl('app_profile_recovery'));
        }

        $this->userService->removeRecoveryAddress($user, $recoveryAddress);
        $this->entityManager->flush();
        $this->addFlash('success', 'profile.recovery.removed');

        return new RedirectResponse($this->generateUrl('app_profile_recovery'));
    }

    #[Route('/profile/password-reset', name: 'app_password_reset_request', methods: ['GET'])]
    public function passwordResetRequest(Request $request): Response
    {
        $session = $request->getSession();

        // One-time display: a successful POST stashed the state in the
        // session and redirected back here, so the code or confirmation
        // survives the client-side redirect but never lingers afterwards.
        $code = $session->remove(self::SESSION_KEY_CODE);

        if (!is_array($code) || !isset($code['token'], $code['number']) || !is_string($code['token']) || !is_int($code['number'])) {
            $code = null;
        }

        $sent = $session->remove(self::SESSION_KEY_SENT);

        return $this->render('profile/password_reset_request.html.twig', [
            'code' => $code,
            'sent' => true === $sent,
        ]);
    }

    /**
     * User-initiated two-key reset: the user generates token and release
     * number on this page and presents both to the administrator in person.
     * Logged-in users start the flow for their own account, anonymous users
     * have to provide their account email address.
     *
     * Anonymous callers always receive the same success response: unknown
     * or inactive accounts get a decoy code that is not backed by a stored
     * request, so the answer never reveals whether the account exists.
     */
    #[Route('/profile/password-reset/code', name: 'app_password_reset_code_start', methods: ['POST'])]
    public function passwordResetCodeStart(Request $request): JsonResponse
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        if (null === $token || !$this->isCsrfTokenValid('password_reset_code', $token)) {
            return AvalynxFormResponse::failure($this->translator->trans('password.reset.invalid_token'));
        }

        $user = $this->getUser();
        $emailRaw = $request->request->get('email');
        $email = is_scalar($emailRaw) ? trim((string) $emailRaw) : '';

        $target = null;

        if ($user instanceof User) {
            $target = $user;
        } else {
            if (!$this->consumeResetAttempt($request)) {
                return $this->tooManyRequests();
            }

            if ('' === $email) {
                return AvalynxFormResponse::invalid([
                    'email' => $this->translator->trans('password.reset.code_email_required'),
                ]);
            }

            if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return AvalynxFormResponse::invalid([
                    'email' => $this->translator->trans('password.reset.code_email_invalid'),
                ]);
            }

            $candidate = $this->userRepository->findOneBy(['email' => strtolower($email)]);

            if (null !== $candidate && $candidate->isActive()) {
                $target = $candidate;
            }
        }

        $clientIp = $request->getClientIp() ?? 'unknown';
        \assert('' !== $clientIp);

        $result = null === $target
            ? $this->adminPasswordResetService->generateDecoyCode()
            : $this->adminPasswordResetService->startUserRequest(
                $target,
                $clientIp,
                anonymous: !$user instanceof User,
            );

        // The code is shown exactly once: it is stashed in the session and
        // survives the client-side redirect, then the GET clears it so it
        // cannot be read again by reloading the page.
        $request->getSession()->set(self::SESSION_KEY_CODE, [
            'token' => $result['token'],
            'number' => $result['number'],
        ]);

        return AvalynxFormResponse::success($this->generateUrl('app_password_reset_request'));
    }

    #[Route('/profile/password-reset', name: 'app_password_reset_send', methods: ['POST'])]
    public function passwordResetSend(Request $request): JsonResponse
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        if (null === $token || !$this->isCsrfTokenValid('password_reset_request', $token)) {
            return AvalynxFormResponse::failure($this->translator->trans('password.reset.invalid_token'));
        }

        if (!$this->consumeResetAttempt($request)) {
            return $this->tooManyRequests();
        }

        $emailRaw = $request->request->get('email');
        $email = is_scalar($emailRaw) ? trim((string) $emailRaw) : '';

        if ('' === $email) {
            return AvalynxFormResponse::invalid([
                'email' => $this->translator->trans('password.reset.email_required'),
            ]);
        }

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return AvalynxFormResponse::invalid([
                'email' => $this->translator->trans('password.reset.email_invalid'),
            ]);
        }

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

        $request->getSession()->set(self::SESSION_KEY_SENT, true);

        return AvalynxFormResponse::success($this->generateUrl('app_password_reset_request'));
    }

    /**
     * The signed link carries a fingerprint of the password it was issued
     * for: a link that was already used (or a password changed by any other
     * means) no longer matches and is rejected like an expired one.
     */
    #[Route('/profile/password-reset/execute/{userId}', name: 'app_profile_password_reset_execute', methods: ['GET'])]
    public function executePasswordReset(Request $request, string $userId): Response
    {
        if (!$this->isSignedRequestValid()) {
            throw $this->createNotFoundException('Invalid or expired reset link.');
        }

        $user = $this->userRepository->find($userId);

        if (null === $user) {
            throw $this->createNotFoundException('User not found.');
        }

        if (!$this->isResetFingerprintValid($request, $user)) {
            throw $this->createNotFoundException('Invalid or expired reset link.');
        }

        return $this->renderResetForm($user);
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

        if (!$this->isResetFingerprintValid($request, $user)) {
            return AvalynxFormResponse::failure($this->translator->trans('password.reset.link_invalid'));
        }

        $passwordRaw = $request->request->get('password');
        $password = is_scalar($passwordRaw) ? (string) $passwordRaw : '';

        if ('' === $password) {
            return AvalynxFormResponse::invalid([
                'password' => $this->translator->trans('password.reset.empty_password'),
            ]);
        }

        if (strlen($password) < 8) {
            return AvalynxFormResponse::invalid([
                'password' => $this->translator->trans('password.reset.password_too_short'),
            ]);
        }

        $passwordConfirmRaw = $request->request->get('password_confirm');
        $passwordConfirm = is_scalar($passwordConfirmRaw) ? (string) $passwordConfirmRaw : '';

        if ($passwordConfirm !== $password) {
            return AvalynxFormResponse::invalid([
                'password_confirm' => $this->translator->trans('password.reset.password_mismatch'),
            ]);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->flush();

        // Whoever held the old credentials must not stay logged in.
        $this->loginSessionService->revokeAll($user);

        $this->addFlash('success', 'password.reset.success');

        return AvalynxFormResponse::success($this->generateUrl('app_login'));
    }

    /**
     * The reset form posts to its own freshly signed URL: the signature of
     * the execute link does not cover the submit endpoint, so handing a
     * plain path to the template would fail verification on submit. The
     * submit URL carries the same password fingerprint as the execute link.
     */
    private function renderResetForm(User $user): Response
    {
        $userId = $user->getId()?->toRfc4122() ?? '';
        $submitUrl = $this->uriSigner->sign(
            $this->userService->withResetFingerprint(
                $this->generateUrl('app_profile_password_reset_submit', ['userId' => $userId], UrlGeneratorInterface::ABSOLUTE_URL),
                $user,
            ),
            new \DateInterval(UserService::RESET_LINK_TTL),
        );

        return $this->render('profile/password_reset.html.twig', [
            'userId' => $userId,
            'submitUrl' => $submitUrl,
        ]);
    }

    private function isResetFingerprintValid(Request $request, User $user): bool
    {
        $fingerprintRaw = $request->query->get(UserService::RESET_FINGERPRINT_PARAMETER);

        return $this->userService->isResetFingerprintValid($user, is_string($fingerprintRaw) ? $fingerprintRaw : null);
    }

    /**
     * Consumes one attempt of the per-IP password reset limiter. Returns
     * false when the limit is exhausted.
     */
    private function consumeResetAttempt(Request $request): bool
    {
        return $this->passwordResetLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted();
    }

    private function tooManyRequests(): JsonResponse
    {
        $response = AvalynxFormResponse::failure($this->translator->trans('password.reset.too_many_requests'));
        $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);

        return $response;
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

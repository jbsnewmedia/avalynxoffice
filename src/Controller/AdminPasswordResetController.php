<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Service\AdminPasswordResetService;
use AvalynxOffice\Util\AvalynxFormResponse;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Administration interface "Passwort-Resets": start a two-key reset for a
 * user without account access, watch the open requests and release them by
 * confirming the release number the user spoke.
 */
final class AdminPasswordResetController extends AbstractController
{
    /**
     * Session key for the one-time display of a freshly created
     * administrator reset code (flow token and release number).
     */
    private const string SESSION_KEY_FLOW = 'admin_password_reset_flow';

    public function __construct(
        private readonly AdminPasswordResetService $adminPasswordResetService,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/password-resets', name: 'app_admin_password_resets', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(Request $request): Response
    {
        // One-time display: a successful start stashed the code in the
        // session and AvalynxForm followed the redirect client-side; the
        // GET clears it so it cannot be read again by reloading the page.
        $flow = null;
        $flowRaw = $request->getSession()->remove(self::SESSION_KEY_FLOW);

        if (is_array($flowRaw) && isset($flowRaw['token'], $flowRaw['number']) && is_string($flowRaw['token']) && is_int($flowRaw['number'])) {
            $flow = [
                'token' => $flowRaw['token'],
                'number' => $flowRaw['number'],
            ];
        }

        return $this->render('admin/password_resets/index.html.twig', [
            'requests' => $this->adminPasswordResetService->listOpenRequests(),
            'flow' => $flow,
        ]);
    }

    #[Route('/admin/password-resets', name: 'app_admin_password_resets_start', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function start(Request $request): JsonResponse
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        if (null === $token || !$this->isCsrfTokenValid('admin_password_reset_start', $token)) {
            return AvalynxFormResponse::failure($this->translator->trans('common.invalid_token'));
        }

        $emailRaw = $request->request->get('email');
        $email = is_scalar($emailRaw) ? trim((string) $emailRaw) : '';

        if ('' === $email) {
            return AvalynxFormResponse::invalid([
                'email' => $this->translator->trans('admin.password_resets.email_required'),
            ]);
        }

        try {
            $result = $this->adminPasswordResetService->startRequest(
                $email,
                $this->adminId(),
                $this->clientIp($request),
            );
        } catch (\DomainException) {
            return AvalynxFormResponse::invalid([
                'email' => $this->translator->trans('admin.password_resets.user_not_found'),
            ]);
        }

        // The token and the release number are shown exactly once: they are
        // stashed in the session and survive the client-side redirect.
        $request->getSession()->set(self::SESSION_KEY_FLOW, [
            'token' => $result['token'],
            'number' => $result['number'],
        ]);

        return AvalynxFormResponse::success($this->generateUrl('app_admin_password_resets'));
    }

    #[Route('/admin/password-resets/{id}/release', name: 'app_admin_password_resets_release', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function release(string $id, Request $request): JsonResponse
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        if (null === $token || !$this->isCsrfTokenValid('admin_password_reset_release_'.$id, $token)) {
            return AvalynxFormResponse::failure($this->translator->trans('common.invalid_token'));
        }

        $resetRequest = $this->adminPasswordResetService->findRequestById($id);

        if (null === $resetRequest) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.password_resets.request_not_found'));
        }

        $numberRaw = $request->request->get('number');
        $number = is_scalar($numberRaw) ? (int) (string) $numberRaw : 0;

        try {
            $this->adminPasswordResetService->release(
                $resetRequest,
                $number,
                $this->adminId(),
                $this->clientIp($request),
            );

            $this->addFlash('success', $this->translator->trans('admin.password_resets.released'));
        } catch (\DomainException $exception) {
            // The internal reason is logged only; the form shows a generic,
            // translated message that does not leak state details.
            $this->logger->info('Password reset release rejected.', [
                'request_id' => $id,
                'reason' => $exception->getMessage(),
            ]);

            return AvalynxFormResponse::invalid([
                'number' => $this->translator->trans('admin.password_resets.release_failed'),
            ]);
        }

        return AvalynxFormResponse::success($this->generateUrl('app_admin_password_resets'));
    }

    /**
     * @return non-empty-string
     */
    private function adminId(): string
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $id = $admin->getId()?->toRfc4122() ?? '';

        if ('' === $id) {
            throw new \RuntimeException('Administrator account has no identifier.');
        }

        return $id;
    }

    /**
     * @return non-empty-string
     */
    private function clientIp(Request $request): string
    {
        $ip = $request->getClientIp();

        if (null === $ip || '' === $ip) {
            return 'unknown';
        }

        return $ip;
    }
}

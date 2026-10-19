<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\PasswordResetRequest;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Service\AdminPasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Administration interface "Passwort-Resets": start a two-key reset for a
 * user without mailbox access, watch the open requests and release them by
 * confirming the release number the user spoke.
 */
final class AdminPasswordResetController extends AbstractController
{
    public function __construct(
        private readonly AdminPasswordResetService $adminPasswordResetService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/password-resets', name: 'app_admin_password_resets', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(): Response
    {
        return $this->render('admin/password_resets/index.html.twig', [
            'requests' => $this->adminPasswordResetService->listOpenRequests(),
            'flow' => null,
        ]);
    }

    #[Route('/admin/password-resets', name: 'app_admin_password_resets_start', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function start(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_password_reset_start');

        $emailRaw = $request->request->get('email');
        $email = is_scalar($emailRaw) ? trim((string) $emailRaw) : '';

        if ('' === $email) {
            $this->addFlash('danger', $this->translator->trans('admin.password_resets.email_required'));

            return $this->redirectToRoute('app_admin_password_resets', [], Response::HTTP_SEE_OTHER);
        }

        try {
            $result = $this->adminPasswordResetService->startRequest(
                $email,
                $this->adminId(),
                $this->clientIp($request),
            );
        } catch (\DomainException) {
            $this->addFlash('danger', $this->translator->trans('admin.password_resets.user_not_found'));

            return $this->redirectToRoute('app_admin_password_resets', [], Response::HTTP_SEE_OTHER);
        }

        $this->addFlash(
            'success',
            $this->translator->trans('admin.password_resets.started', [
                '%token%' => $result['token'],
                '%number%' => $result['number'],
                '%user%' => $result['request']->getUser()?->getEmail() ?? '',
            ]),
        );

        return $this->redirectToRoute('app_admin_password_resets', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/password-resets/{id}/release', name: 'app_admin_password_resets_release', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function release(string $id, Request $request): Response
    {
        $this->assertCsrf($request, 'admin_password_reset_release_'.$id);

        $resetRequest = $this->adminPasswordResetService->findRequestById($id);

        if (null === $resetRequest) {
            $this->addFlash('danger', $this->translator->trans('admin.password_resets.request_not_found'));

            return $this->redirectToRoute('app_admin_password_resets', [], Response::HTTP_SEE_OTHER);
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
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_password_resets', [], Response::HTTP_SEE_OTHER);
    }

    private function assertCsrf(Request $request, string $id): void
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        if (null === $token || !$this->isCsrfTokenValid($id, $token)) {
            throw new AccessDeniedHttpException('Invalid password reset form token.');
        }
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

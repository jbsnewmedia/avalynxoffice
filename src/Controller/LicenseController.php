<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Licensing\Entity\StoredLicenseKey;
use AvalynxOffice\Licensing\Service\LicenseManager;
use AvalynxOffice\Licensing\Service\UserSeatCounter;
use AvalynxOffice\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administration interface "Lizenz & Nutzung" and the user facing license
 * information page.
 */
final class LicenseController extends AbstractController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly LicenseManager $licenseManager,
        private readonly UserSeatCounter $seatCounter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/license', name: 'app_license', methods: ['GET'])]
    public function index(): Response
    {
        $entitlement = $this->licenseManager->getEntitlement();

        return $this->render('license/info.html.twig', [
            'entitlement' => $entitlement,
            'trustedKeysConfigured' => $this->licenseManager->areTrustedKeysConfigured(),
        ]);
    }

    #[Route(path: '/admin/license', name: 'license_admin', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function admin(): Response
    {
        $entitlement = $this->licenseManager->getEntitlement();

        return $this->render('license/index.html.twig', [
            'entitlement' => $entitlement,
            'trustedKeysConfigured' => $this->licenseManager->areTrustedKeysConfigured(),
            'storedKeys' => $this->entityManager->getRepository(StoredLicenseKey::class)->findBy([], ['addedAt' => 'ASC']),
        ]);
    }

    #[Route(path: '/admin/users', name: 'license_admin_users', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function users(UserService $userService): Response
    {
        $seatUsers = $this->seatCounter->getSeatUsers();

        // The template disables the deactivate/revoke buttons for the one
        // account that keeps the installation administrable.
        $lastActiveAdminId = null;
        foreach ($seatUsers as $seatUser) {
            if ($userService->isLastActiveAdmin($seatUser)) {
                $lastActiveAdminId = $seatUser->getId()?->toRfc4122();

                break;
            }
        }

        return $this->render('license/users.html.twig', [
            'entitlement' => $this->licenseManager->getEntitlement(),
            'seatUsers' => $seatUsers,
            'lastActiveAdminId' => $lastActiveAdminId,
        ]);
    }

    #[Route(path: '/admin/license/keys', name: 'license_admin_add_keys', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function addKeys(Request $request): Response
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null === $token || !$this->isCsrfTokenValid('add_license_keys', $token)) {
            throw new AccessDeniedHttpException('Invalid license key form token.');
        }

        $keysRaw = $request->request->get('license_keys');
        $keysRaw = is_scalar($keysRaw) ? (string) $keysRaw : '';
        $parts = preg_split('/[\r\n,]+/', $keysRaw) ?: [];

        $existing = [];
        foreach ($this->entityManager->getRepository(StoredLicenseKey::class)->findAll() as $stored) {
            $existing[$stored->getKeyHash()] = true;
        }

        $added = 0;
        foreach ($parts as $part) {
            $key = trim($part);
            if ('' === $key) {
                continue;
            }

            $hash = hash('sha256', $key);
            if (isset($existing[$hash])) {
                continue;
            }

            $existing[$hash] = true;
            $this->entityManager->persist(new StoredLicenseKey($key));
            ++$added;
        }

        $this->entityManager->flush();

        $this->addFlash('success', 'license.keys.added');

        return $this->redirectToRoute('license_admin', [], Response::HTTP_SEE_OTHER);
    }

    #[Route(path: '/admin/license/keys/{id}/delete', name: 'license_admin_delete_key', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteKey(string $id, Request $request): Response
    {
        $stored = $this->entityManager->find(StoredLicenseKey::class, $id);
        if (!$stored instanceof StoredLicenseKey) {
            throw $this->createNotFoundException();
        }

        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null === $token || !$this->isCsrfTokenValid('delete_license_key_'.$stored->getId(), $token)) {
            throw new AccessDeniedHttpException('Invalid license key delete token.');
        }

        $this->entityManager->remove($stored);
        $this->entityManager->flush();

        $this->addFlash('success', 'license.keys.deleted');

        return $this->redirectToRoute('license_admin', [], Response::HTTP_SEE_OTHER);
    }

    #[Route(path: '/admin/users/{id}/active', name: 'license_admin_toggle_user', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleUser(string $id, Request $request): Response
    {
        $user = $this->entityManager->find(User::class, $id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null === $token || !$this->isCsrfTokenValid('toggle_user_active_'.$user->getId(), $token)) {
            throw new AccessDeniedHttpException('Invalid user toggle token.');
        }

        // Deactivation reduces the seat usage and must stay possible in
        // read-only mode. Reactivation is a user-initiated change and is
        // blocked while the installation is read-only.
        if (!$user->isActive() && $this->licenseManager->isReadOnly()) {
            throw new AccessDeniedHttpException('Cannot activate users while the installation is in read-only mode.');
        }

        if ($user->isActive() && $this->userService->isLastActiveAdmin($user)) {
            throw new AccessDeniedHttpException('The last active administrator cannot be deactivated.');
        }

        $user->setActive(!$user->isActive());
        $this->entityManager->flush();

        return $this->redirectToRoute('license_admin_users', [], Response::HTTP_SEE_OTHER);
    }

    #[Route(path: '/admin/users/{id}/toggle-admin', name: 'license_admin_toggle_role', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleAdminRole(string $id, Request $request, UserService $userService): Response
    {
        $user = $this->entityManager->find(User::class, $id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null === $token || !$this->isCsrfTokenValid('toggle_user_admin_'.$user->getId(), $token)) {
            throw new AccessDeniedHttpException('Invalid user admin toggle token.');
        }

        // Role changes are user-initiated changes and are blocked while the
        // installation is read-only.
        if ($this->licenseManager->isReadOnly()) {
            throw new AccessDeniedHttpException('Cannot change user roles while the installation is in read-only mode.');
        }

        try {
            $userService->toggleAdmin($user);
        } catch (\DomainException) {
            throw new AccessDeniedHttpException('The last active administrator cannot lose the administrator role.');
        }

        $this->entityManager->flush();

        return $this->redirectToRoute('license_admin_users', [], Response::HTTP_SEE_OTHER);
    }
}

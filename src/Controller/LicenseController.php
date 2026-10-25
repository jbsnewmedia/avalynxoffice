<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Licensing\Entity\StoredLicenseKey;
use AvalynxOffice\Licensing\Service\LicenseManager;
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
        private readonly LicenseManager $licenseManager,
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
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;
use AvalynxOffice\Service\LegalContentService;
use AvalynxOffice\Util\AvalynxFormResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class LegalController extends AbstractController
{
    public function __construct(
        private readonly LegalContentService $legalContentService,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    #[Route('/admin/legal', name: 'app_settings_legal')]
    #[IsGranted('ROLE_ADMIN')]
    public function admin(): Response
    {
        $pages = [];
        foreach (LegalContentService::PAGES as $page) {
            $pages[$page] = $this->legalContentService->getContent($page);
        }

        return $this->render('legal/admin.html.twig', [
            'pages' => $pages,
        ]);
    }

    #[Route('/admin/legal/save', name: 'app_settings_legal_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function save(Request $request, TranslatorInterface $translator): JsonResponse
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);

        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        if (null === $token || !$this->isCsrfTokenValid('save_legal', $token)) {
            return AvalynxFormResponse::failure($translator->trans('common.invalid_token'));
        }

        $pageRaw = $request->request->get('page');
        $page = is_scalar($pageRaw) ? (string) $pageRaw : '';

        if (!in_array($page, LegalContentService::PAGES, true)) {
            throw new NotFoundHttpException('Legal page not found.');
        }

        $contentRaw = $request->request->get('content');
        $content = is_scalar($contentRaw) ? (string) $contentRaw : '';

        $this->legalContentService->setContent($page, $content);

        $this->addFlash('success', 'legal.admin.saved');

        return AvalynxFormResponse::success($this->generateUrl('app_settings_legal'));
    }

    #[Route('/legal', name: 'app_legal')]
    public function index(): Response
    {
        return $this->render('legal/index.html.twig');
    }

    #[Route('/legal/{page}', name: 'app_legal_page')]
    public function page(string $page): Response
    {
        if (!in_array($page, LegalContentService::PAGES, true)) {
            throw new NotFoundHttpException('Legal page not found.');
        }

        return $this->render('legal/show.html.twig', [
            'page' => $page,
            'content' => $this->legalContentService->getContent($page),
        ]);
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;
use AvalynxOffice\Repository\MailboxRepository;
use AvalynxOffice\Service\ThemeService;
use AvalynxOffice\Util\AvalynxFormResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class SettingsController extends AbstractController
{
    public function __construct(
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    #[Route('/settings', name: 'app_settings')]
    public function index(Request $request, MailboxRepository $mailboxRepository, ThemeService $themeService): Response
    {
        $response = $this->render('settings/index.html.twig', [
            'current_uri' => $request->getUri(),
            'mailboxes' => $mailboxRepository->findBy([], ['name' => 'ASC']),
            'primary_color' => $themeService->getPrimaryColor(),
        ]);

        // The page embeds CSRF tokens: no cache (browser, proxy or anything
        // in between) may ever reuse a rendered body, otherwise a stale
        // token of a previous session would be submitted and rejected. The
        // internal header disables the session listener's automatic
        // cache-control override so our no-store survives the response.
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, '1');

        return $response;
    }

    #[Route('/settings/appearance', name: 'app_settings_appearance', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveAppearance(Request $request, ThemeService $themeService, TranslatorInterface $translator): JsonResponse
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);

        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null === $token || !$this->isCsrfTokenValid('save_appearance', $token)) {
            return AvalynxFormResponse::failure($translator->trans('common.invalid_token'));
        }

        $colorRaw = $request->request->get('primary_color');
        $color = is_scalar($colorRaw) ? (string) $colorRaw : '';

        try {
            $themeService->setPrimaryColor($color);
        } catch (\InvalidArgumentException) {
            return AvalynxFormResponse::invalid([
                'primary_color' => $translator->trans('settings.appearance.invalid_color'),
            ]);
        }

        $this->addFlash('success', $translator->trans('settings.appearance.saved'));

        return AvalynxFormResponse::success($this->generateUrl('app_settings'));
    }
}

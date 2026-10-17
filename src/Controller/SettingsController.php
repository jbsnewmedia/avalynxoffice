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
use AvalynxOffice\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SettingsController extends AbstractController
{
    public function __construct(
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    #[Route('/settings', name: 'app_settings')]
    public function index(Request $request, SettingsService $settingsService, MailboxRepository $mailboxRepository): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }
        $newsletterOptIn = $settingsService->isNewsletterOptIn();

        return $this->render('settings/_modal_content.html.twig', [
            'current_uri' => $request->getUri(),
            'newsletter_optin' => $newsletterOptIn,
            'mailboxes' => $mailboxRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/settings/newsletter', name: 'app_settings_newsletter', methods: ['POST'])]
    public function saveNewsletter(Request $request, SettingsService $settingsService): Response
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
        $optIn = $request->request->has('newsletter_optin');
        $settingsService->setNewsletterOptIn($optIn);
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true]);
        }
        $target = $this->generateUrl('app_settings');

        return new RedirectResponse($target);
    }
}

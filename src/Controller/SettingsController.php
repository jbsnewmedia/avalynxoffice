<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;
use AvalynxOffice\Service\ThemeService;
use AvalynxOffice\Util\AvalynxFormResponse;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/settings', name: 'app_settings')]
    public function index(Request $request): Response
    {
        return $this->settingsPage($request, 'settings/index.html.twig', []);
    }

    #[Route('/settings/language', name: 'app_settings_language')]
    public function language(Request $request): Response
    {
        return $this->settingsPage($request, 'settings/language.html.twig', []);
    }

    #[Route('/settings/design', name: 'app_settings_design')]
    public function design(Request $request, ThemeService $themeService): Response
    {
        return $this->settingsPage($request, 'settings/design.html.twig', [
            'primary_color' => $themeService->getPrimaryColor(),
        ]);
    }

    #[Route('/settings/time', name: 'app_settings_time', methods: ['GET'])]
    public function time(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->settingsPage($request, 'settings/time.html.twig', [
            'user_timezone' => $user->getTimezone(),
            'user_time_format' => $user->getTimeFormat(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    #[Route('/settings/time', name: 'app_settings_time_save', methods: ['POST'])]
    public function saveTime(Request $request, TranslatorInterface $translator): JsonResponse
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null === $token || !$this->isCsrfTokenValid('save_time', $token)) {
            return AvalynxFormResponse::failure($translator->trans('common.invalid_token'));
        }

        $timezoneRaw = $request->request->get('timezone');
        $timezone = is_scalar($timezoneRaw) ? (string) $timezoneRaw : '';
        if ('' !== $timezone && !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return AvalynxFormResponse::invalid([
                'timezone' => $translator->trans('settings.time.invalid_timezone'),
            ]);
        }

        $formatRaw = $request->request->get('time_format');
        $format = is_scalar($formatRaw) ? (string) $formatRaw : '';
        if ('' !== $format && !in_array($format, ['short', 'medium', 'long'], true)) {
            return AvalynxFormResponse::invalid([
                'time_format' => $translator->trans('settings.time.invalid_format'),
            ]);
        }

        /** @var User $user */
        $user = $this->getUser();
        $user->setTimezone('' !== $timezone ? $timezone : null);
        $user->setTimeFormat('' !== $format ? $format : null);
        $this->entityManager->flush();

        $this->addFlash('success', $translator->trans('settings.time.saved'));

        return AvalynxFormResponse::success($this->generateUrl('app_settings_time'));
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

        return AvalynxFormResponse::success($this->generateUrl('app_settings_design'));
    }

    /**
     * Renders a settings page. Every settings page embeds CSRF tokens or
     * session state: no cache (browser, proxy or anything in between) may
     * ever reuse a rendered body, otherwise a stale token of a previous
     * session would be submitted and rejected. The internal header disables
     * the session listener's automatic cache-control override so our
     * no-store survives the response.
     *
     * @param array<string, mixed> $parameters
     */
    private function settingsPage(Request $request, string $template, array $parameters): Response
    {
        $response = $this->render($template, ['current_uri' => $request->getUri()] + $parameters);

        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, '1');

        return $response;
    }
}

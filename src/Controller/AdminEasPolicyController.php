<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Form\EasPolicyType;
use AvalynxOffice\Service\Eas\EasPolicyService;
use AvalynxOffice\Service\SystemEnvironmentService;
use AvalynxOffice\Util\AvalynxFormResponse;
use AvalynxOffice\Util\FormInvalidMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin area "EAS device policies": the global ActiveSync provisioning
 * policy for every device. Restrictions are opt-in — with nothing
 * enabled, devices are provisioned without any limitation. The page is
 * only functional when the EAS endpoint is configured; otherwise the
 * form is replaced by a hint, mirroring the system status page.
 */
#[Route('/admin/eas-policy')]
#[IsGranted('ROLE_ADMIN')]
final class AdminEasPolicyController extends AbstractController
{
    public function __construct(
        private readonly EasPolicyService $policyService,
        private readonly SystemEnvironmentService $systemEnvironment,
    ) {
    }

    #[Route('', name: 'app_admin_eas_policy', methods: ['GET'])]
    public function index(): Response
    {
        $form = $this->createForm(EasPolicyType::class, null, [
            'action' => $this->generateUrl('app_admin_eas_policy_save'),
            'stored' => $this->policyService->stored(),
        ]);

        return $this->render('admin/eas_policy.html.twig', [
            'form' => $form,
            'eas' => $this->systemEnvironment->easStatus(),
        ]);
    }

    #[Route('', name: 'app_admin_eas_policy_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedException();
        }

        $form = $this->createForm(EasPolicyType::class, null, [
            'action' => $this->generateUrl('app_admin_eas_policy_save'),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $this->policyService->save($this->policyValues($request, $form));

        $this->addFlash('success', 'admin.eas_policy.saved');

        return AvalynxFormResponse::success($this->generateUrl('app_admin_eas_policy'));
    }

    /**
     * Translates the submitted form values into policy deviations. The
     * checkbox state is read from the raw request with a strict value
     * comparison: a restriction activates ONLY when its exact activating
     * value arrives — anything else (absent, empty, tampered) means off,
     * because this setting must never enable a restriction by accident.
     *
     * @template TData
     *
     * @param FormInterface<TData> $form
     *
     * @return array<string, string|null>
     */
    private function policyValues(Request $request, FormInterface $form): array
    {
        $submitted = $request->request->all('eas_policy');

        $values = [];

        foreach (EasPolicyService::TOGGLES as $key => $onValue) {
            $raw = \is_string($submitted[$key] ?? null) ? $submitted[$key] : null;
            $values[$key] = $raw === $onValue ? $onValue : null;
        }

        foreach (EasPolicyService::NUMBERS as $key => $definition) {
            $raw = $form->get($key)->getData();
            $values[$key] = is_numeric($raw) ? (string) (int) $raw : null;
        }

        return $values;
    }
}

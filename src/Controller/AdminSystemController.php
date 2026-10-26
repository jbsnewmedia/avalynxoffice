<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Form\SyncSettingsType;
use AvalynxOffice\Repository\SyncRunRepository;
use AvalynxOffice\Service\Sync\SyncSettingsService;
use AvalynxOffice\Util\AvalynxFormResponse;
use AvalynxOffice\Util\FormInvalidMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin area "System status": the latest sync service runs and the
 * configuration of the sync service (batch sizes and pauses). Changes to
 * the configuration apply on the next sync pass without a restart.
 */
#[Route('/admin/system')]
#[IsGranted('ROLE_ADMIN')]
final class AdminSystemController extends AbstractController
{
    public function __construct(
        private readonly SyncSettingsService $syncSettings,
        private readonly SyncRunRepository $syncRunRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_admin_system', methods: ['GET'])]
    public function index(): Response
    {
        $form = $this->createForm(SyncSettingsType::class, null, [
            'action' => $this->generateUrl('app_admin_system_save'),
            'settings' => $this->syncSettings->all(),
        ]);

        return $this->render('admin/system.html.twig', [
            'form' => $form,
            'lastSyncRun' => $this->syncRunRepository->findLatest(),
            'recentRuns' => $this->syncRunRepository->findBy([], ['startedAt' => 'DESC'], 5),
        ]);
    }

    #[Route('', name: 'app_admin_system_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedException();
        }

        $form = $this->createForm(SyncSettingsType::class, null, [
            'action' => $this->generateUrl('app_admin_system_save'),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $interval = $this->intField($form, 'intervalSeconds');
        $busy = $this->intField($form, 'busyIntervalSeconds');
        $mailsLimit = $this->intField($form, 'mailsLimit');
        $contactsLimit = $this->intField($form, 'contactsLimit');
        $calendarLimit = $this->intField($form, 'calendarLimit');

        if ($busy > $interval) {
            $form->get('busyIntervalSeconds')->addError(new FormError($this->translator->trans('admin.system.busy_le_interval')));

            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $this->syncSettings->save([
            'intervalSeconds' => $interval,
            'busyIntervalSeconds' => $busy,
            'mailsLimit' => $mailsLimit,
            'contactsLimit' => $contactsLimit,
            'calendarLimit' => $calendarLimit,
        ]);

        return AvalynxFormResponse::success($this->generateUrl('app_admin_system'), [
            'message' => $this->translator->trans('admin.system.saved'),
        ]);
    }

    /**
     * @template TData
     *
     * @param FormInterface<TData> $form
     */
    private function intField(FormInterface $form, string $name): int
    {
        $value = $form->get($name)->getData();

        // The form validates NotBlank + numeric, so this is always an int
        // for a valid submission.
        return is_numeric($value) ? (int) $value : 0;
    }
}

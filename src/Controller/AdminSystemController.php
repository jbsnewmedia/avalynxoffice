<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\SyncRun;
use AvalynxOffice\Form\SyncSettingsType;
use AvalynxOffice\Repository\ConnectorRepository;
use AvalynxOffice\Repository\SyncRunRepository;
use AvalynxOffice\Service\ConnectorPluginRegistry;
use AvalynxOffice\Service\Idn\IdnService;
use AvalynxOffice\Service\Sync\SyncSettingsService;
use AvalynxOffice\Service\SystemEnvironmentService;
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
        private readonly IdnService $idnService,
        private readonly SystemEnvironmentService $systemEnvironment,
    ) {
    }

    #[Route('', name: 'app_admin_system', methods: ['GET'])]
    public function index(ConnectorRepository $connectorRepository, ConnectorPluginRegistry $pluginRegistry): Response
    {
        $form = $this->createForm(SyncSettingsType::class, null, [
            'action' => $this->generateUrl('app_admin_system_save'),
            'settings' => $this->syncSettings->all(),
        ]);

        return $this->render('admin/system.html.twig', [
            'form' => $form,
        ] + $this->statusData($connectorRepository, $pluginRegistry));
    }

    /**
     * Live-refresh fragment for the system page: renders only the status
     * cards (latest run, recent runs, connectors) so the page can poll
     * the important values without a full reload.
     */
    #[Route('/status', name: 'app_admin_system_status', methods: ['GET'])]
    public function status(Request $request, ConnectorRepository $connectorRepository, ConnectorPluginRegistry $pluginRegistry): Response
    {
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedException();
        }

        return $this->render('admin/_system_status.html.twig', $this->statusData($connectorRepository, $pluginRegistry));
    }

    /**
     * @return array{lastSyncRun: SyncRun|null, recentRuns: list<SyncRun>, connectors: list<array{name: string, domain: string, plugin: string|null, inputConfigured: bool, status: array{mails: ?string, contacts: ?string, calendar: ?string}}>, runtime: array{docker: bool, eas: array{configured: bool, available: bool, url: string|null, statusCode: int|null, latencyMs: int|null, detail: string}}}
     */
    private function statusData(ConnectorRepository $connectorRepository, ConnectorPluginRegistry $pluginRegistry): array
    {
        return [
            'lastSyncRun' => $this->syncRunRepository->findLatest(),
            'recentRuns' => $this->syncRunRepository->findBy([], ['startedAt' => 'DESC'], 5),
            'connectors' => $this->connectorOverview($connectorRepository, $pluginRegistry),
            'runtime' => [
                'docker' => $this->systemEnvironment->isDocker(),
                'eas' => $this->systemEnvironment->easStatus(),
            ],
        ];
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

        $this->addFlash('success', 'admin.system.saved');

        return AvalynxFormResponse::success($this->generateUrl('app_admin_system'));
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

    /**
     * Every connector docks into the sync process automatically: the
     * overview shows which plugin drives each connector and how far the
     * data is already synced.
     *
     * @return list<array{name: string, domain: string, plugin: string|null, inputConfigured: bool, status: array{mails: ?string, contacts: ?string, calendar: ?string}}>
     */
    private function connectorOverview(ConnectorRepository $connectorRepository, ConnectorPluginRegistry $pluginRegistry): array
    {
        $connectors = [];
        foreach ($connectorRepository->findBy([], ['name' => 'ASC']) as $connector) {
            $pluginKey = $connector->getPluginKey();
            $plugin = null !== $pluginKey ? $pluginRegistry->get($pluginKey) : null;
            $connectors[] = [
                'name' => $connector->getName(),
                'domain' => $this->idnService->toUnicode($connector->getDomain()),
                'plugin' => null !== $plugin ? $this->translator->trans($plugin->getName()) : $pluginKey,
                'inputConfigured' => $pluginRegistry->isInputConfigured($connector),
                'status' => $pluginRegistry->getStatus($connector),
            ];
        }

        return $connectors;
    }
}

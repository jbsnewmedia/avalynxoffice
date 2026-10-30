<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Form\ConnectorType;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;
use AvalynxOffice\Repository\ConnectorRepository;
use AvalynxOffice\Service\ConnectorConfigService;
use AvalynxOffice\Service\ConnectorPluginRegistry;
use AvalynxOffice\Service\ConnectorService;
use AvalynxOffice\Util\AvalynxFormResponse;
use AvalynxOffice\Util\FormInvalidMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Connector administration: the list is an AvalynxDataTable, new connectors
 * are created in a modal. The technical configuration (base data and
 * plugins) lives on the settings page of each connector.
 */
#[Route('/admin/connectors')]
#[IsGranted('ROLE_ADMIN')]
class ConnectorAdminController extends AbstractController
{
    /**
     * Columns the admin table may be sorted by, mapped to their query
     * builder field names. Everything else from the client is ignored.
     *
     * @var array<string, string>
     */
    private const array SORTABLE_COLUMNS = [
        'name' => 'c.name',
        'domain' => 'c.domain',
    ];

    private const array PER_PAGE_CHOICES = [10, 25, 50, 100];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ConnectorRepository $connectorRepository,
        private readonly ConnectorPluginRegistry $pluginRegistry,
    ) {
    }

    #[Route('', name: 'connector_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('connector/index.html.twig', [
            'readOnly' => $this->licenseEnforcement->isReadOnly(),
        ]);
    }

    #[Route('/data', name: 'connector_data', methods: ['POST'])]
    public function data(Request $request): JsonResponse
    {
        $tokenRaw = $request->request->get('_token') ?? $request->headers->get('X-CSRF-Token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null === $token || !$this->isCsrfTokenValid('datatable', $token)) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('common.invalid_token')], Response::HTTP_FORBIDDEN);
        }

        $search = trim((string) $request->request->get('search', ''));
        $page = max(1, (int) $request->request->get('page', 1));
        $perPage = (int) $request->request->get('perpage', self::PER_PAGE_CHOICES[0]);
        if (!in_array($perPage, self::PER_PAGE_CHOICES, true)) {
            $perPage = self::PER_PAGE_CHOICES[0];
        }
        $sorting = $this->parseSorting((string) $request->request->get('sorting', ''));

        $total = $this->connectorRepository->countConnectorsForAdminList('');
        $filtered = $this->connectorRepository->countConnectorsForAdminList($search);
        $offset = ($page - 1) * $perPage;
        $connectors = $this->connectorRepository->findConnectorsForAdminList($search, $this->toOrderBy($sorting), $offset, $perPage);

        $rows = [];
        foreach ($connectors as $connector) {
            $rows[] = [
                'data' => [
                    'id' => $connector->getId()?->toRfc4122(),
                    'name' => htmlspecialchars($connector->getName(), ENT_QUOTES, 'UTF-8'),
                    'domain' => htmlspecialchars($connector->getDomain(), ENT_QUOTES, 'UTF-8').$this->renderPluginLine($connector),
                    'defaultAccount' => $this->renderDefaultAccount($connector),
                    'input' => $this->renderConfiguredBadge($this->pluginRegistry->isInputConfigured($connector)),
                    'output' => $this->renderConfiguredBadge($this->pluginRegistry->isOutputConfigured($connector)),
                    'status' => $this->renderStatus($this->pluginRegistry->getStatus($connector)),
                    'options' => $this->renderOptions($connector),
                ],
                'class' => '',
            ];
        }

        return new JsonResponse([
            'head' => ['columns' => [
                ['name' => $this->translator->trans('connector.name'), 'sortable' => true, 'id' => 'name'],
                ['name' => $this->translator->trans('connector.domain'), 'sortable' => true, 'id' => 'domain'],
                ['name' => $this->translator->trans('connector.default_account'), 'raw' => true, 'id' => 'defaultAccount'],
                ['name' => $this->translator->trans('connector.input'), 'raw' => true, 'id' => 'input'],
                ['name' => $this->translator->trans('connector.output'), 'raw' => true, 'id' => 'output'],
                ['name' => $this->translator->trans('common.status'), 'raw' => true, 'id' => 'status'],
                ['name' => $this->translator->trans('connector.actions'), 'raw' => true, 'id' => 'options', 'class' => 'avalynx-datatable-options', 'fixed' => 'end'],
            ]],
            'data' => $rows,
            'count' => [
                // "all" is read by avalynx-datatable 1.x, "total" is kept for
                // the documented JSON structure.
                'total' => $total,
                'all' => $total,
                'filtered' => $filtered,
                'start' => 0 === $filtered ? 0 : $offset + 1,
                'end' => min($offset + $perPage, $filtered),
                'perpage' => $perPage,
                'page' => $page,
            ],
            'sorting' => $sorting,
            'search' => ['value' => $search],
        ]);
    }

    #[Route('/new', name: 'connector_new', methods: ['GET'])]
    public function newModal(Request $request, ConnectorPluginRegistry $pluginRegistry): Response
    {
        $this->requireXmlHttpRequest($request);

        $connector = new Connector();
        $form = $this->createForm(ConnectorType::class, $connector, [
            'action' => $this->generateUrl('connector_create'),
            'pluginChoices' => $this->pluginChoices(),
        ]);

        return $this->render('connector/_modal_form.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/new', name: 'connector_create', methods: ['POST'])]
    public function create(Request $request, ConnectorService $connectorService, ConnectorPluginRegistry $pluginRegistry): JsonResponse
    {
        $this->requireXmlHttpRequest($request);

        $connector = new Connector();
        $form = $this->createForm(ConnectorType::class, $connector, [
            'action' => $this->generateUrl('connector_create'),
            'pluginChoices' => $this->pluginChoices(),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
        $connectorService->save($connector, true);
        $this->addFlash('success', $this->translator->trans('connector.created'));

        // The connector was created with a chosen plugin: jump straight to
        // the settings card of that plugin.
        $settingsUrl = $this->generateUrl('connector_settings', ['id' => $connector->getId()]);
        if (null !== $connector->getPluginKey() && null !== $pluginRegistry->get($connector->getPluginKey())) {
            $settingsUrl .= '#plugin-'.$connector->getPluginKey();
        }

        return AvalynxFormResponse::success($settingsUrl);
    }

    #[Route('/{id}/edit', name: 'connector_edit', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function edit(Connector $connector, Request $request, ConnectorService $connectorService, ConnectorPluginRegistry $pluginRegistry): JsonResponse
    {
        $this->requireXmlHttpRequest($request);

        $formerDefaultAccount = $connector->getDefaultAccount();
        $form = $this->createForm(ConnectorType::class, $connector, [
            'action' => $this->generateUrl('connector_edit', ['id' => $connector->getId()]),
            'pluginChoices' => $this->pluginChoices($connector),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
        $connectorService->save($connector, formerDefaultAccount: $formerDefaultAccount);
        $this->addFlash('success', $this->translator->trans('connector.saved'));

        return AvalynxFormResponse::success($this->generateUrl('connector_settings', ['id' => $connector->getId()]));
    }

    #[Route('/{id}/settings', name: 'connector_settings', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function settings(Connector $connector, ConnectorPluginRegistry $pluginRegistry): Response
    {
        $baseForm = $this->createForm(ConnectorType::class, $connector, [
            'action' => $this->generateUrl('connector_edit', ['id' => $connector->getId()]),
            'pluginChoices' => $this->pluginChoices($connector),
        ]);

        $pluginForms = [];
        foreach ($pluginRegistry->pluginsFor($connector) as $plugin) {
            $pluginForms[] = [
                'plugin' => $plugin,
                'form' => $this->createForm($plugin->getSettingsFormType(), $pluginRegistry->getConfig($connector, $plugin), [
                    'action' => $this->generateUrl('connector_plugin_settings', ['id' => $connector->getId(), 'plugin' => $plugin->getKey()]),
                ])->createView(),
            ];
        }

        return $this->render('connector/settings.html.twig', [
            'connector' => $connector,
            'baseForm' => $baseForm->createView(),
            'pluginForms' => $pluginForms,
        ]);
    }

    #[Route('/{id}/settings/{plugin}', name: 'connector_plugin_settings', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function pluginSettings(Connector $connector, string $plugin, Request $request, ConnectorPluginRegistry $pluginRegistry, ConnectorConfigService $configService): Response
    {
        $pluginInstance = $pluginRegistry->get($plugin);
        if (null === $pluginInstance) {
            throw new NotFoundHttpException(sprintf('Unknown connector plugin "%s".', $plugin));
        }

        $form = $this->createForm($pluginInstance->getSettingsFormType(), $pluginRegistry->getConfig($connector, $pluginInstance), [
            'action' => $this->generateUrl('connector_plugin_settings', ['id' => $connector->getId(), 'plugin' => $pluginInstance->getKey()]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
            $config = $form->getData();
            $configService->save($connector, $pluginInstance->getKey(), is_array($config) ? $config : []);
            $this->addFlash('success', $this->translator->trans('connector.plugin_saved'));

            return AvalynxFormResponse::success($this->generateUrl('connector_settings', ['id' => $connector->getId()]));
        }

        return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
    }

    #[Route('/{id}', name: 'connector_delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function delete(Connector $connector, Request $request, ConnectorService $connectorService): Response
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null !== $token && $this->isCsrfTokenValid('delete_connector_'.$connector->getId()?->toRfc4122(), $token)) {
            $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
            $connectorService->delete($connector);
            $this->addFlash('success', $this->translator->trans('connector.deleted'));
        } else {
            $this->addFlash('danger', $this->translator->trans('common.invalid_token'));
        }

        return $this->redirectToRoute('connector_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Validates the client sorting JSON and returns it keyed by column id
     * (the datatable echoes this back into its own state, so the keys must
     * match the column ids).
     *
     * @return array<string, string> column id => direction
     */
    private function parseSorting(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['name' => 'asc'];
        }

        $sorting = [];
        foreach ($decoded as $column => $direction) {
            if (!is_string($column) || !isset(self::SORTABLE_COLUMNS[$column])) {
                continue;
            }

            $sorting[$column] = is_string($direction) && 'desc' === strtolower($direction) ? 'desc' : 'asc';
        }

        return [] === $sorting ? ['name' => 'asc'] : $sorting;
    }

    /**
     * Translates the validated column sorting into the order-by map for the
     * query builder.
     *
     * @param array<string, string> $sorting column id => direction
     *
     * @return array<string, string> query field => direction
     */
    private function toOrderBy(array $sorting): array
    {
        $orderBy = [];
        foreach ($sorting as $column => $direction) {
            $orderBy[self::SORTABLE_COLUMNS[$column]] = $direction;
        }

        return $orderBy;
    }

    private function renderDefaultAccount(Connector $connector): string
    {
        $defaultAccount = $connector->getDefaultAccount();
        if (null === $defaultAccount) {
            return '<span class="text-body-secondary small">'.htmlspecialchars($this->translator->trans('connector.no_default_account'), ENT_QUOTES, 'UTF-8').'</span>';
        }

        return '<div>'.htmlspecialchars($defaultAccount->getName(), ENT_QUOTES, 'UTF-8').'</div>'
            .'<div class="small text-body-secondary">'.htmlspecialchars($defaultAccount->getEmail(), ENT_QUOTES, 'UTF-8').'</div>';
    }

    private function renderConfiguredBadge(bool $configured): string
    {
        if ($configured) {
            return '<span class="badge text-bg-success">'.htmlspecialchars($this->translator->trans('connector.configured_yes'), ENT_QUOTES, 'UTF-8').'</span>';
        }

        return '<span class="badge text-bg-secondary">'.htmlspecialchars($this->translator->trans('connector.configured_no'), ENT_QUOTES, 'UTF-8').'</span>';
    }

    /**
     * @param array{mails: ?string, contacts: ?string, calendar: ?string} $status
     */
    private function renderStatus(array $status): string
    {
        $entries = [
            'connector.status_mails' => $status['mails'] ?? null,
            'connector.status_contacts' => $status['contacts'] ?? null,
            'connector.status_calendar' => $status['calendar'] ?? null,
        ];

        $lines = [];
        foreach ($entries as $key => $value) {
            $lines[] = '<div>'.htmlspecialchars($this->translator->trans($key), ENT_QUOTES, 'UTF-8').': '
                .htmlspecialchars(null !== $value ? (string) $value : '—', ENT_QUOTES, 'UTF-8').'</div>';
        }

        return '<div class="small">'.implode('', $lines).'</div>';
    }

    private function renderOptions(Connector $connector): string
    {
        $connectorId = $connector->getId()?->toRfc4122();
        $readOnly = $this->licenseEnforcement->isReadOnly();
        $settingsLabel = htmlspecialchars($this->translator->trans('connector.settings'), ENT_QUOTES, 'UTF-8');
        $deleteLabel = htmlspecialchars($this->translator->trans('email.delete'), ENT_QUOTES, 'UTF-8');

        $settingsUrl = $this->generateUrl('connector_settings', ['id' => $connectorId]);
        $html = '<a href="'.htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8').'"'
            .' class="btn btn-sm btn-primary me-1"'
            .' title="'.$settingsLabel.'" aria-label="'.$settingsLabel.'">'
            .'<i class="fa-solid fa-gear" aria-hidden="true"></i></a>';

        $deleteUrl = $this->generateUrl('connector_delete', ['id' => $connectorId]);
        $confirm = htmlspecialchars($this->translator->trans('connector.confirm_delete'), ENT_QUOTES, 'UTF-8');

        $html .= '<form method="post" action="'.htmlspecialchars($deleteUrl, ENT_QUOTES, 'UTF-8').'" class="d-inline">'
            .'<input type="hidden" name="_token" value="'
            .htmlspecialchars($this->csrfTokenManager->getToken('delete_connector_'.$connectorId)->getValue(), ENT_QUOTES, 'UTF-8').'">'
            .'<button type="submit" class="btn btn-sm btn-danger confirm-action"'
            .' data-confirm="'.$confirm.'" title="'.$deleteLabel.'" aria-label="'.$deleteLabel.'"'
            .' '.($readOnly ? 'disabled' : '').'>'
            .'<i class="fa-solid fa-trash" aria-hidden="true"></i></button></form>';

        return $html;
    }

    /**
     * Translated plugin choices for the connector form (value = plugin key).
     * When the connector already uses a plugin that is not installed
     * anymore, the stored key is appended so the form keeps rendering.
     *
     * @return array<string, string> label => plugin key
     */
    private function pluginChoices(?Connector $connector = null): array
    {
        $choices = [];
        foreach ($this->pluginRegistry->all() as $plugin) {
            $choices[$this->translator->trans($plugin->getName())] = $plugin->getKey();
        }
        $current = $connector?->getPluginKey();
        if (null !== $current && '' !== $current && !in_array($current, $choices, true)) {
            $choices[$current] = $current;
        }

        return $choices;
    }

    /**
     * Shows the plugin driving the connector below the domain in the admin
     * table.
     */
    private function renderPluginLine(Connector $connector): string
    {
        $pluginKey = $connector->getPluginKey();
        if (null === $pluginKey || '' === $pluginKey) {
            return '';
        }
        $plugin = $this->pluginRegistry->get($pluginKey);
        $label = null !== $plugin ? $this->translator->trans($plugin->getName()) : $pluginKey;

        return '<div class="small text-body-secondary">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</div>';
    }

    private function requireXmlHttpRequest(Request $request): void
    {
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedException();
        }
    }
}

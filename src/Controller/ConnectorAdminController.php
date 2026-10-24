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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/connectors')]
#[IsGranted('ROLE_ADMIN')]
class ConnectorAdminController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    #[Route('', name: 'connector_index', methods: ['GET'])]
    public function index(ConnectorRepository $repo, ConnectorPluginRegistry $pluginRegistry): Response
    {
        return $this->render('connector/index.html.twig', [
            'items' => $repo->findBy([], ['name' => 'ASC']),
            'pluginRegistry' => $pluginRegistry,
        ]);
    }

    #[Route('/new', name: 'connector_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ConnectorService $connectorService): Response
    {
        $connector = new Connector();
        $form = $this->createForm(ConnectorType::class, $connector, [
            'action' => $this->generateUrl('connector_new'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
            $connectorService->save($connector, true);
            $this->addFlash('success', $this->translator->trans('connector.created'));

            return AvalynxFormResponse::success($this->generateUrl('connector_settings', ['id' => $connector->getId()]));
        }

        if ($request->isMethod('POST')) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        return $this->render('connector/form.html.twig', [
            'form' => $form->createView(),
            'title' => $this->translator->trans('connector.new'),
        ]);
    }

    #[Route('/{id}/edit', name: 'connector_edit', methods: ['GET', 'POST'])]
    public function edit(Connector $connector, Request $request, ConnectorService $connectorService): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->redirectToRoute('connector_settings', ['id' => $connector->getId()]);
        }

        $formerDefaultAccount = $connector->getDefaultAccount();
        $form = $this->createForm(ConnectorType::class, $connector, [
            'action' => $this->generateUrl('connector_edit', ['id' => $connector->getId()]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
            $connectorService->save($connector, formerDefaultAccount: $formerDefaultAccount);
            $this->addFlash('success', $this->translator->trans('connector.saved'));

            return AvalynxFormResponse::success($this->generateUrl('connector_settings', ['id' => $connector->getId()]));
        }

        return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
    }

    #[Route('/{id}/settings', name: 'connector_settings', methods: ['GET'])]
    public function settings(Connector $connector, ConnectorPluginRegistry $pluginRegistry): Response
    {
        $baseForm = $this->createForm(ConnectorType::class, $connector, [
            'action' => $this->generateUrl('connector_edit', ['id' => $connector->getId()]),
        ]);

        $pluginForms = [];
        foreach ($pluginRegistry->all() as $plugin) {
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

    #[Route('/{id}/settings/{plugin}', name: 'connector_plugin_settings', methods: ['POST'])]
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

    #[Route('/{id}', name: 'connector_delete', methods: ['POST'])]
    public function delete(Connector $connector, Request $request, ConnectorService $connectorService): Response
    {
        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;
        if (null !== $token && $this->isCsrfTokenValid('delete_connector_'.$connector->getId(), $token)) {
            $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
            $connectorService->delete($connector);
            $this->addFlash('success', $this->translator->trans('connector.deleted'));
        } else {
            $this->addFlash('danger', $this->translator->trans('common.invalid_token'));
        }

        return $this->redirectToRoute('connector_index', [], Response::HTTP_SEE_OTHER);
    }
}

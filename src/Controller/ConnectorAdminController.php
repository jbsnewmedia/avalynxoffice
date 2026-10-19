<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\MailboxConnector;
use AvalynxOffice\Form\MailboxConnectorType;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;
use AvalynxOffice\Repository\MailboxConnectorRepository;
use AvalynxOffice\Service\MailboxConnectorService;
use AvalynxOffice\Util\AvalynxFormResponse;
use AvalynxOffice\Util\FormInvalidMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/settings/connectors')]
class ConnectorAdminController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    #[Route('', name: 'connector_index', methods: ['GET'])]
    public function index(MailboxConnectorRepository $repo): Response
    {
        return $this->render('connector/index.html.twig', [
            'items' => $repo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'connector_new', methods: ['GET', 'POST'])]
    public function new(Request $request, MailboxConnectorService $connectorService): Response
    {
        $connector = new MailboxConnector();
        $form = $this->createForm(MailboxConnectorType::class, $connector, [
            'action' => $this->generateUrl('connector_new'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
            $this->applyPasswordFields($form, $connector);
            $connectorService->save($connector, true);
            $this->addFlash('success', $this->translator->trans('connector.created'));

            return AvalynxFormResponse::success($this->generateUrl('connector_index'));
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
    public function edit(MailboxConnector $connector, Request $request, MailboxConnectorService $connectorService): Response
    {
        $form = $this->createForm(MailboxConnectorType::class, $connector, [
            'action' => $this->generateUrl('connector_edit', ['id' => $connector->getId()]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
            $this->applyPasswordFields($form, $connector);
            $connectorService->save($connector);
            $this->addFlash('success', $this->translator->trans('connector.saved'));

            return AvalynxFormResponse::success($this->generateUrl('connector_index'));
        }

        if ($request->isMethod('POST')) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        return $this->render('connector/form.html.twig', [
            'form' => $form->createView(),
            'title' => $this->translator->trans('connector.edit'),
        ]);
    }

    #[Route('/{id}', name: 'connector_delete', methods: ['POST'])]
    public function delete(MailboxConnector $connector, Request $request, MailboxConnectorService $connectorService): Response
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

    /**
     * @param FormInterface<MailboxConnector> $form
     */
    private function applyPasswordFields(FormInterface $form, MailboxConnector $connector): void
    {
        $pop3Password = $form->get('pop3Password')->getData();
        if (is_string($pop3Password) && '' !== $pop3Password) {
            $connector->setPop3Password($pop3Password);
        }

        $smtpPassword = $form->get('smtpPassword')->getData();
        if (is_string($smtpPassword) && '' !== $smtpPassword) {
            $connector->setSmtpPassword($smtpPassword);
        }
    }
}

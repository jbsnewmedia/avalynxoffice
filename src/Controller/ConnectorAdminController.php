<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MailboxConnector;
use App\Form\MailboxConnectorType;
use App\Licensing\Service\LicenseEnforcementInterface;
use App\Licensing\Service\LicenseOperation;
use App\Repository\MailboxConnectorRepository;
use App\Service\MailboxConnectorService;
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
    public function index(Request $request, MailboxConnectorRepository $repo): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }

        return $this->render('connector/_modal_index.html.twig', [
            'items' => $repo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'connector_new', methods: ['GET', 'POST'])]
    public function new(Request $request, MailboxConnectorService $connectorService): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }

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

            return $this->redirectToRoute('connector_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('connector/_modal_form.html.twig', [
            'form' => $form->createView(),
            'title' => $this->translator->trans('connector.new'),
        ]);
    }

    #[Route('/{id}/edit', name: 'connector_edit', methods: ['GET', 'POST'])]
    public function edit(MailboxConnector $connector, Request $request, MailboxConnectorService $connectorService): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }

        $form = $this->createForm(MailboxConnectorType::class, $connector, [
            'action' => $this->generateUrl('connector_edit', ['id' => $connector->getId()]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->licenseEnforcement->assertCanPerform(LicenseOperation::SETTINGS_WRITE);
            $this->applyPasswordFields($form, $connector);
            $connectorService->save($connector);
            $this->addFlash('success', $this->translator->trans('connector.saved'));

            return $this->redirectToRoute('connector_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('connector/_modal_form.html.twig', [
            'form' => $form->createView(),
            'title' => $this->translator->trans('connector.edit'),
        ]);
    }

    #[Route('/{id}', name: 'connector_delete', methods: ['POST'])]
    public function delete(MailboxConnector $connector, Request $request, MailboxConnectorService $connectorService): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }

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

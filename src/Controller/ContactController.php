<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Form\ContactFormType;
use AvalynxOffice\Repository\AddressRepository;
use AvalynxOffice\Repository\MailboxRepository;
use AvalynxOffice\Service\ContactService;
use AvalynxOffice\Service\Mail\UserMailboxProvider;
use AvalynxOffice\Util\AvalynxFormResponse;
use AvalynxOffice\Util\FormInvalidMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class ContactController extends AbstractController
{
    public function __construct(
        private readonly UserMailboxProvider $mailboxProvider,
        private readonly AddressRepository $addressRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly ContactService $contactService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/contacts', name: 'contact_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderContacts('all', null, $request);
    }

    #[Route(path: '/contacts/{groupId}', name: 'contact_group', methods: ['GET'], requirements: ['groupId' => 'all|[A-Z]|other'])]
    public function group(string $groupId, Request $request): Response
    {
        return $this->renderContacts($groupId, null, $request);
    }

    #[Route(path: '/contacts/{groupId}/{contactId}', name: 'contact_group_contact', methods: ['GET'], requirements: ['groupId' => 'all|[A-Z]|other', 'contactId' => '[0-9a-fA-F\-]{36}'])]
    public function groupContact(string $groupId, string $contactId, Request $request): Response
    {
        return $this->renderContacts($groupId, $contactId, $request);
    }

    #[Route(path: '/contacts/new', name: 'contact_new_modal', methods: ['GET'])]
    public function newModal(Request $request): Response
    {
        $this->requireXmlHttpRequest($request);

        $user = $this->getUser();
        \assert($user instanceof User);

        $address = new Address();
        $address->setEmail('');

        $form = $this->createForm(ContactFormType::class, $address, [
            'action' => $this->generateUrl('contact_new_modal'),
            'mailboxes' => $this->mailboxProvider->forUser($user),
        ]);

        return $this->render('contacts/_modal_form.html.twig', [
            'form' => $form,
            'title' => $this->translator->trans('contact.new'),
        ]);
    }

    #[Route(path: '/contacts/new', name: 'contact_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireXmlHttpRequest($request);

        $user = $this->getUser();
        \assert($user instanceof User);

        $address = new Address();
        $address->setEmail('');
        $mailboxes = $this->mailboxProvider->forUser($user);

        $form = $this->createForm(ContactFormType::class, $address, [
            'action' => $this->generateUrl('contact_new_modal'),
            'mailboxes' => $mailboxes,
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $mailbox = $form->get('mailbox')->getData();
        \assert($mailbox instanceof Mailbox);

        $this->contactService->createFromForm($mailbox, $address);

        return AvalynxFormResponse::success($this->resolveContactRedirect($request, 'all'));
    }

    private function resolveContactRedirect(Request $request, string $fallbackGroupId): string
    {
        $referer = $request->headers->get('referer');

        return is_string($referer) && '' !== $referer
            ? $referer
            : $this->generateUrl('contact_group', ['groupId' => $fallbackGroupId]);
    }

    #[Route(path: '/contacts/{groupId}/{contactId}/modal', name: 'contact_edit_modal', methods: ['GET'], requirements: ['groupId' => 'all|[A-Z]|other', 'contactId' => '[0-9a-fA-F\-]{36}'])]
    public function editModal(string $groupId, string $contactId, Request $request): Response
    {
        $this->requireXmlHttpRequest($request);

        $address = $this->resolveContact($contactId);

        $form = $this->createForm(ContactFormType::class, $address, [
            'action' => $this->generateUrl('contact_edit_modal', ['groupId' => $groupId, 'contactId' => $contactId]),
        ]);

        return $this->render('contacts/_modal_form.html.twig', [
            'form' => $form,
            'title' => $this->translator->trans('contact.edit'),
        ]);
    }

    #[Route(path: '/contacts/{groupId}/{contactId}/modal', name: 'contact_update', methods: ['POST'], requirements: ['groupId' => 'all|[A-Z]|other', 'contactId' => '[0-9a-fA-F\-]{36}'])]
    public function update(string $groupId, string $contactId, Request $request): Response
    {
        $this->requireXmlHttpRequest($request);

        $address = $this->resolveContact($contactId);

        $form = $this->createForm(ContactFormType::class, $address, [
            'action' => $this->generateUrl('contact_edit_modal', ['groupId' => $groupId, 'contactId' => $contactId]),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $this->contactService->save($address);

        return AvalynxFormResponse::success($this->resolveContactRedirect($request, $groupId));
    }

    #[Route(path: '/contacts/{groupId}/{contactId}/delete', name: 'contact_delete', methods: ['POST'], requirements: ['groupId' => 'all|[A-Z]|other', 'contactId' => '[0-9a-fA-F\-]{36}'])]
    public function delete(string $groupId, string $contactId, Request $request): Response
    {
        $address = $this->resolveContact($contactId);

        $tokenRaw = $request->request->get('_token');
        $token = is_scalar($tokenRaw) ? (string) $tokenRaw : null;

        if (null === $token || !$this->isCsrfTokenValid('delete_contact_'.$contactId, $token)) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'error' => $this->translator->trans('common.invalid_token')], Response::HTTP_BAD_REQUEST);
            }

            throw new AccessDeniedException('Invalid contact delete token.');
        }

        $this->contactService->delete($address);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true]);
        }

        return $this->redirectToRoute('contact_group', ['groupId' => $groupId]);
    }

    private function renderContacts(string $groupId, ?string $activeContactId, ?Request $request = null): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $mailboxes = $this->mailboxProvider->forUser($user);
        $activeMailbox = $this->resolveMailbox($request, $mailboxes);
        $scopeMailboxes = null !== $activeMailbox ? [$activeMailbox] : $mailboxes;
        $search = trim((string) ($request?->query->get('q', '') ?? ''));

        $allContacts = $this->addressRepository->findContactsForMailboxes($scopeMailboxes);
        $groups = $this->buildGroups($allContacts);

        if ('all' !== $groupId && !isset($groups[$groupId])) {
            throw new NotFoundHttpException('Contact group not found.');
        }

        $contacts = $allContacts;
        if ('' !== $search) {
            $contacts = $this->addressRepository->findContactsForMailboxes($scopeMailboxes, $search);
        }

        if ('all' !== $groupId) {
            $contacts = array_values(array_filter(
                $contacts,
                static fn (Address $contact): bool => self::groupKeyFor($contact) === $groupId,
            ));
        }

        $activeContact = null;
        if (null !== $activeContactId) {
            foreach ($contacts as $contact) {
                if ((string) $contact->getId() === $activeContactId) {
                    $activeContact = $contact;

                    break;
                }
            }
        }
        $activeContact ??= $contacts[0] ?? null;

        $activeContactMailboxes = null === $activeContact ? [] : array_values(array_filter(
            $mailboxes,
            static fn (Mailbox $mailbox): bool => $mailbox->getAddresses()->contains($activeContact),
        ));

        // When all mailboxes are shown, every list item is tagged with the
        // mailbox(es) its contact belongs to (like the mail list does).
        $contactMailboxes = [];
        if (null === $activeMailbox) {
            foreach ($mailboxes as $mailbox) {
                foreach ($mailbox->getAddresses() as $address) {
                    $id = $address->getId();
                    if (null !== $id) {
                        $contactMailboxes[$id->toRfc4122()][] = $mailbox;
                    }
                }
            }
        }

        return $this->render('contacts/index.html.twig', [
            'mailboxes' => $mailboxes,
            'activeMailbox' => $activeMailbox,
            'activeMailboxId' => $this->mailboxId($activeMailbox),
            'groups' => $groups,
            'activeGroup' => $groupId,
            'contacts' => $contacts,
            'activeContact' => $activeContact,
            'activeContactMailboxes' => $activeContactMailboxes,
            'contactMailboxes' => $contactMailboxes,
            'search' => $search,
            'totalContacts' => count($allContacts),
            'fallbackEmail' => 'unknown@localhost',
        ]);
    }

    /**
     * Finds a contact by id among the contacts of the user's mailboxes.
     */
    private function resolveContact(string $contactId): Address
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        try {
            $addressId = \Symfony\Component\Uid\UuidV7::fromString($contactId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Contact not found.');
        }

        foreach ($this->mailboxProvider->forUser($user) as $mailbox) {
            $contact = $this->contactService->contactForMailbox($mailbox, $addressId->toRfc4122());

            if (null !== $contact) {
                return $contact;
            }
        }

        throw new NotFoundHttpException('Contact not found.');
    }

    private function requireXmlHttpRequest(Request $request): void
    {
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedException();
        }
    }

    /**
     * Resolves the mailbox scope from the request. A mailbox the user has no
     * access to is rejected instead of silently ignored.
     *
     * @param list<Mailbox> $mailboxes
     */
    private function resolveMailbox(?Request $request, array $mailboxes): ?Mailbox
    {
        $mailboxId = (string) ($request?->query->get('mailbox', 'all') ?? 'all');

        if ('all' === $mailboxId || '' === $mailboxId) {
            return null;
        }

        $mailbox = $this->mailboxRepository->find($mailboxId);

        if (null === $mailbox || !in_array($mailbox, $mailboxes, true)) {
            throw new AccessDeniedException();
        }

        return $mailbox;
    }

    private function mailboxId(?Mailbox $mailbox): string
    {
        return null !== $mailbox ? (string) $mailbox->getId() : 'all';
    }

    /**
     * Letter groups with contact counts, built from the unfiltered contact
     * list so counts stay stable while searching.
     *
     * @param list<Address> $contacts
     *
     * @return array<string, int>
     */
    private function buildGroups(array $contacts): array
    {
        $groups = [];

        foreach ($contacts as $contact) {
            $key = self::groupKeyFor($contact);
            $groups[$key] = ($groups[$key] ?? 0) + 1;
        }

        ksort($groups, SORT_STRING);

        return $groups;
    }

    private static function groupKeyFor(Address $contact): string
    {
        $name = $contact->getLastname() ?? $contact->getName() ?? $contact->getEmail();
        $key = mb_strtoupper(mb_substr(trim($name), 0, 1));

        return preg_match('/^[A-Z]$/', $key) ? $key : 'other';
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Licensing\Service\LicenseOperation;
use AvalynxOffice\Repository\AccountRepository;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Repository\FolderRepository;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\EmailDetailTemplateResolver;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class MailController extends AbstractController
{
    private const int DEFAULT_LIMIT = 10;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly MailService $mailService,
        private readonly FolderManager $folderManager,
        private readonly EmailDetailTemplateResolver $emailDetailTemplateResolver,
        private readonly AccountRepository $accountRepo,
        private readonly FolderRepository $folderRepo,
        private readonly EmailRepository $emailRepo,
        private readonly EntityManagerInterface $entityManager,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
    ) {
    }

    #[Route(path: '/', name: 'home')]
    public function home(): Response
    {
        return $this->redirectToRoute('mail_index');
    }

    #[Route(path: '/mails', name: 'mail_index')]
    public function index(): Response
    {
        $accounts = $this->accountsForUser();
        foreach ($accounts as $mb) {
            $this->folderManager->ensureDefaultFolders($mb);
        }
        $firstFolderByAccount = $this->mailService->getFirstFolderByAccount($accounts);
        if (0 === count($accounts)) {
            return $this->renderMailIndex([
                'accounts' => $accounts,
                'firstFolderByAccount' => $firstFolderByAccount,
                'allMails' => true,
            ]);
        }
        $rawFolders = $this->folderRepo->findFoldersLinkedToAccounts($accounts);
        $rawCounts = $this->mailService->countEmailsPerFolder($rawFolders);
        $grouped = $this->mailService->groupFoldersByName($rawFolders, $rawCounts);
        $emails = $this->allMailsInFolders($rawFolders);
        $totalEmails = count($emails);
        $totalMails = $totalEmails;
        $limit = self::DEFAULT_LIMIT;
        $emails = array_slice($emails, 0, $limit);

        return $this->renderMailIndex([
            'accounts' => $accounts,
            'activeAccount' => null,
            'folders' => $grouped['folders'],
            'activeFolder' => null,
            'allMails' => true,
            'emails' => $emails,
            'activeEmail' => $emails[0] ?? null,
            'folderEmailCounts' => $grouped['counts'],
            'firstFolderByAccount' => $firstFolderByAccount,
            'totalEmails' => $totalEmails,
            'totalMails' => $totalMails,
            'limit' => $limit,
            'offset' => count($emails),
        ]);
    }

    #[Route(path: '/mails/{accountId}/{folderId}/{emailId}', name: 'mail_folder_email', requirements: ['accountId' => 'all|[0-9a-fA-F\-]{36}', 'folderId' => 'all|[0-9a-fA-F\-]{36}', 'emailId' => '[0-9a-fA-F\-]{36}'])]
    #[Route(path: '/mails/{accountId}/{folderId}', name: 'mail_folder', requirements: ['accountId' => 'all|[0-9a-fA-F\-]{36}', 'folderId' => 'all|[0-9a-fA-F\-]{36}'])]
    public function folder(string $accountId, string $folderId, ?string $emailId = null): Response
    {
        $accounts = $this->accountsForUser();
        foreach ($accounts as $mb) {
            $this->folderManager->ensureDefaultFolders($mb);
        }
        $firstFolderByAccount = $this->mailService->getFirstFolderByAccount($accounts);
        $activeAccount = 'all' === $accountId ? null : $this->accountRepo->find($accountId);
        if (null !== $activeAccount && !in_array($activeAccount, $this->accountsForUser(), true)) {
            throw $this->createAccessDeniedException();
        }
        $allMails = 'all' === $folderId;
        $activeFolder = $allMails ? null : $this->folderRepo->find($folderId);
        if (0 === count($accounts)) {
            return $this->renderMailIndex([
                'accounts' => $accounts,
                'firstFolderByAccount' => $firstFolderByAccount,
                'allMails' => $allMails,
            ]);
        }
        if ($activeAccount) {
            $folders = $this->folderRepo->findByAccountOrderByAssignment($activeAccount);
            $folderEmailCounts = $this->mailService->countEmailsPerFolder($folders);
            $rawFolders = $folders;
        } else {
            $rawFolders = $this->folderRepo->findFoldersLinkedToAccounts($accounts);
            $rawCounts = $this->mailService->countEmailsPerFolder($rawFolders);
            $grouped = $this->mailService->groupFoldersByName($rawFolders, $rawCounts);
            $folders = $grouped['folders'];
            $folderEmailCounts = $grouped['counts'];
        }
        if (!$allMails && $activeFolder) {
            $allowedIds = array_map(static fn (Folder $f) => (string) $f->getId(), $folders);
            if (!in_array((string) $activeFolder->getId(), $allowedIds, true)) {
                $activeFolder = $folders[0] ?? null;
            }
        }
        $limit = self::DEFAULT_LIMIT;
        $offset = 0;
        $emails = [];
        $totalEmails = 0;
        $totalMails = count($this->mailService->collectEmails($rawFolders));
        $activeEmail = null;
        if ($allMails) {
            $emails = $this->allMailsInFolders($rawFolders);
            $totalEmails = count($emails);
        } elseif ($activeFolder) {
            if (!$activeAccount) {
                $emails = $this->mailService->collectEmails(
                    $this->mailService->foldersInSameGroup($rawFolders, $activeFolder),
                );
            } else {
                $emails = $this->mailService->collectEmails([$activeFolder], false);
            }
            $emails = $this->mailService->sortByDateDescending($emails);
            $totalEmails = count($emails);
        }

        if (null !== $emailId) {
            foreach ($emails as $index => $email) {
                if ((string) $email->getId() !== $emailId) {
                    continue;
                }

                $activeEmail = $email;
                $offset = intdiv($index, $limit) * $limit;

                break;
            }
        }

        $emails = array_slice($emails, $offset, $limit);

        if (null !== $activeEmail) {
            foreach ($emails as $email) {
                if ((string) $email->getId() === $emailId) {
                    $activeEmail = $email;

                    break;
                }
            }
        }
        $activeEmail ??= $emails[0] ?? null;

        return $this->renderMailIndex([
            'accounts' => $accounts,
            'activeAccount' => $activeAccount,
            'folders' => $folders,
            'activeFolder' => $activeFolder,
            'allMails' => $allMails,
            'emails' => $emails,
            'activeEmail' => $activeEmail,
            'folderEmailCounts' => $folderEmailCounts,
            'firstFolderByAccount' => $firstFolderByAccount,
            'totalEmails' => $totalEmails,
            'totalMails' => $totalMails,
            'limit' => $limit,
            'offset' => $offset + count($emails),
        ]);
    }

    #[Route(path: '/email/{id}', name: 'mail_email', requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function email(string $id, Request $request): Response
    {
        $email = $this->emailRepo->find($id);
        if (!$email) {
            throw $this->createNotFoundException($this->translator->trans('email.not_found'));
        }
        $this->denyAccessToEmail($email);

        return $this->render($this->emailDetailTemplateResolver->resolveTemplate(), [
            'email' => $email,
            'activeAccountId' => $request->query->get('accountId'),
        ]);
    }

    #[Route(path: '/email/{emailId}/attachment/{attachmentId}', name: 'mail_email_attachment_download', requirements: ['emailId' => '[0-9a-fA-F\-]{36}', 'attachmentId' => '[0-9a-fA-F\-]{36}'])]
    public function downloadAttachment(string $emailId, string $attachmentId, AttachmentStorage $attachmentStorage): Response
    {
        $email = $this->emailRepo->find($emailId);
        if (!$email) {
            throw $this->createNotFoundException($this->translator->trans('email.not_found'));
        }
        $this->denyAccessToEmail($email);

        foreach ($email->getEmailAttachments() as $link) {
            $attachment = $link->getAttachment();
            if (null !== $attachment && (string) $attachment->getId() === $attachmentId) {
                try {
                    return $attachmentStorage->createDownloadResponse($attachment);
                } catch (\RuntimeException $e) {
                    throw $this->createNotFoundException($e->getMessage());
                }
            }
        }

        throw $this->createNotFoundException($this->translator->trans('email.attachment_not_found'));
    }

    #[Route(path: '/email/{id}/favorite', name: 'mail_email_favorite', requirements: ['id' => '[0-9a-fA-F\-]{36}'], methods: ['POST'])]
    public function setFavorite(string $id, Request $request): JsonResponse
    {
        $this->licenseEnforcement->assertCanPerform(LicenseOperation::MAIL_MODIFY);
        $email = $this->emailRepo->find($id);
        if (!$email) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('email.not_found')], Response::HTTP_NOT_FOUND);
        }
        try {
            $this->denyAccessToEmail($email);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('common.access_denied')], Response::HTTP_FORBIDDEN);
        }
        $param = $request->request->get('favorite') ?? $request->query->get('favorite');
        $favorite = in_array($param, ['1', 'true', 1, true], true);

        // Use existing logic for toggle/set favorite
        $email->setIsFavorite($favorite);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'favorite' => $email->isFavorite()]);
    }

    #[Route(path: '/folder/{folderId}/more', name: 'mail_folder_more', requirements: ['folderId' => 'all|[0-9a-fA-F\-]{36}'])]
    public function folderMore(string $folderId, Request $request): Response
    {
        $offset = (int) $request->query->get('offset', '0');
        $limit = (int) $request->query->get('limit', (string) self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        if ($offset < 0) {
            $offset = 0;
        }
        $accountId = (string) $request->query->get('accountId', 'all');
        $activeAccount = 'all' === $accountId ? null : $this->accountRepo->find($accountId);
        $folder = null;

        if ('all' === $folderId) {
            if (null !== $activeAccount && !in_array($activeAccount, $this->accountsForUser(), true)) {
                return new Response($this->translator->trans('common.access_denied'), Response::HTTP_FORBIDDEN);
            }
            $scopeFolders = $activeAccount
                ? $this->folderRepo->findByAccountOrderByAssignment($activeAccount)
                : $this->folderRepo->findFoldersLinkedToAccounts($this->accountsForUser());
            $emails = $this->mailService->collectEmails($scopeFolders);
        } else {
            $folder = $this->folderRepo->find($folderId);
            if (!$folder) {
                return new Response($this->translator->trans('folder.not_found'), Response::HTTP_NOT_FOUND);
            }
            $emails = 'all' === $accountId
                ? $this->mailService->collectEmails(
                    $this->mailService->foldersInSameGroup(
                        $this->folderRepo->findFoldersLinkedToAccounts($this->accountsForUser()),
                        $folder,
                    ),
                )
                : $this->mailService->collectEmails([$folder], false);
        }
        $emails = $this->mailService->sortByDateDescending($emails);
        $slice = array_slice($emails, $offset, $limit);
        $html = $this->renderView('mail/_email_items.html.twig', [
            'emails' => $slice,
            'activeEmail' => null,
            'activeAccount' => $activeAccount,
            'activeFolder' => $folder,
        ]);

        return new Response($html);
    }

    /**
     * All emails of the given folders, deduplicated and sorted by date descending.
     *
     * @param array<Folder> $folders
     *
     * @return array<Email>
     */
    private function allMailsInFolders(array $folders): array
    {
        return $this->mailService->sortByDateDescending(
            $this->mailService->collectEmails($folders),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderMailIndex(array $params): Response
    {
        return $this->render('mail/index.html.twig', array_merge([
            'accounts' => [],
            'activeAccount' => null,
            'folders' => [],
            'activeFolder' => null,
            'allMails' => false,
            'emails' => [],
            'activeEmail' => null,
            'folderEmailCounts' => [],
            'firstFolderByAccount' => [],
            'totalEmails' => 0,
            'totalMails' => 0,
            'limit' => self::DEFAULT_LIMIT,
            'offset' => 0,
            'emailDetailTemplate' => $this->emailDetailTemplateResolver->resolveTemplate(),
        ], $params));
    }

    protected function denyAccessToEmail(Email $email): void
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return;
        }

        $account = $email->getAccount();
        if (null === $account) {
            throw $this->createAccessDeniedException();
        }

        foreach ($user->getAccounts() as $allowedAccount) {
            if ($allowedAccount === $account) {
                return;
            }
        }

        throw $this->createAccessDeniedException();
    }

    /**
     * Accounts visible to the current user. Users with the admin role see
     * every account, all other users only the accounts linked to them.
     *
     * @return array<Account>
     */
    private function accountsForUser(): array
    {
        $user = $this->getUser();

        if ($user instanceof User && !in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $user->getAccounts()->toArray();
        }

        return $this->accountRepo->findBy([], ['name' => 'ASC']);
    }
}

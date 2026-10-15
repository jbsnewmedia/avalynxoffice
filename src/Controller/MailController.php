<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Email;
use App\Entity\Folder;
use App\Entity\Mailbox;
use App\Entity\User;
use App\Licensing\Service\LicenseEnforcementInterface;
use App\Licensing\Service\LicenseOperation;
use App\Repository\EmailRepository;
use App\Repository\FolderRepository;
use App\Repository\MailboxRepository;
use App\Service\Mail\AttachmentStorage;
use App\Service\Mail\EmailDetailTemplateResolver;
use App\Service\Mail\FolderManager;
use App\Service\Mail\MailService;
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
        private readonly MailboxRepository $mailboxRepo,
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
        $mailboxes = $this->mailboxesForUser();
        foreach ($mailboxes as $mb) {
            $this->folderManager->ensureDefaultFolders($mb);
        }
        $firstFolderByMailbox = $this->mailService->getFirstFolderByMailbox($mailboxes);
        $activeMailbox = null;
        if (0 === count($mailboxes)) {
            return $this->renderMailIndex([
                'mailboxes' => $mailboxes,
                'firstFolderByMailbox' => $firstFolderByMailbox,
            ]);
        }
        $rawFolders = $this->folderRepo->findFoldersLinkedToMailboxes($mailboxes);
        $rawCounts = $this->mailService->countEmailsPerFolder($rawFolders);
        $grouped = $this->mailService->groupFoldersByName($rawFolders, $rawCounts);
        $folders = $grouped['folders'];
        $folderEmailCounts = $grouped['counts'];
        $activeFolder = null;
        foreach ($folders as $f) {
            $fid = (string) $f->getId();
            if (($folderEmailCounts[$fid] ?? 0) > 0) {
                $activeFolder = $f;
                break;
            }
        }
        if (!$activeFolder) {
            $activeFolder = $folders[0] ?? null;
        }
        $emails = [];
        $totalEmails = 0;
        $limit = self::DEFAULT_LIMIT;
        $offset = 0;
        if ($activeFolder) {
            $emails = $this->mailService->sortByDateDescending(
                $this->mailService->collectEmails(
                    $this->mailService->foldersInSameGroup($rawFolders, $activeFolder),
                ),
            );
            $totalEmails = count($emails);
            $emails = array_slice($emails, $offset, $limit);
        }
        $activeEmail = $emails[0] ?? null;

        return $this->renderMailIndex([
            'mailboxes' => $mailboxes,
            'activeMailbox' => $activeMailbox,
            'folders' => $folders,
            'activeFolder' => $activeFolder,
            'emails' => $emails,
            'activeEmail' => $activeEmail,
            'folderEmailCounts' => $folderEmailCounts,
            'firstFolderByMailbox' => $firstFolderByMailbox,
            'totalEmails' => $totalEmails,
            'limit' => $limit,
            'offset' => $offset + count($emails),
        ]);
    }

    #[Route(path: '/mails/{mailboxId}/{folderId}/{emailId}', name: 'mail_folder_email', requirements: ['mailboxId' => 'all|[0-9a-fA-F\-]{36}', 'folderId' => '[0-9a-fA-F\-]{36}', 'emailId' => '[0-9a-fA-F\-]{36}'])]
    #[Route(path: '/mails/{mailboxId}/{folderId}', name: 'mail_folder', requirements: ['mailboxId' => 'all|[0-9a-fA-F\-]{36}', 'folderId' => '[0-9a-fA-F\-]{36}'])]
    public function folder(string $mailboxId, string $folderId, ?string $emailId = null): Response
    {
        $mailboxes = $this->mailboxesForUser();
        foreach ($mailboxes as $mb) {
            $this->folderManager->ensureDefaultFolders($mb);
        }
        $firstFolderByMailbox = $this->mailService->getFirstFolderByMailbox($mailboxes);
        $activeMailbox = 'all' === $mailboxId ? null : $this->mailboxRepo->find($mailboxId);
        if (null !== $activeMailbox && !in_array($activeMailbox, $this->mailboxesForUser(), true)) {
            throw $this->createAccessDeniedException();
        }
        $activeFolder = $this->folderRepo->find($folderId);
        if (0 === count($mailboxes)) {
            return $this->renderMailIndex([
                'mailboxes' => $mailboxes,
                'firstFolderByMailbox' => $firstFolderByMailbox,
            ]);
        }
        if ($activeMailbox) {
            $folders = $this->folderRepo->findByMailboxOrderByAssignment($activeMailbox);
            $folderEmailCounts = $this->mailService->countEmailsPerFolder($folders);
            $rawFolders = $folders;
        } else {
            $rawFolders = $this->folderRepo->findFoldersLinkedToMailboxes($mailboxes);
            $rawCounts = $this->mailService->countEmailsPerFolder($rawFolders);
            $grouped = $this->mailService->groupFoldersByName($rawFolders, $rawCounts);
            $folders = $grouped['folders'];
            $folderEmailCounts = $grouped['counts'];
        }
        if ($activeFolder) {
            $allowedIds = array_map(static fn (Folder $f) => (string) $f->getId(), $folders);
            if (!in_array((string) $activeFolder->getId(), $allowedIds, true)) {
                $activeFolder = $folders[0] ?? null;
            }
        }
        $limit = self::DEFAULT_LIMIT;
        $offset = 0;
        $emails = [];
        $totalEmails = 0;
        $activeEmail = null;
        if ($activeFolder) {
            if (!$activeMailbox) {
                $emails = $this->mailService->collectEmails(
                    $this->mailService->foldersInSameGroup($rawFolders, $activeFolder),
                );
            } else {
                $emails = $this->mailService->collectEmails([$activeFolder], false);
            }
            $emails = $this->mailService->sortByDateDescending($emails);
            $totalEmails = count($emails);

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
        }
        $activeEmail ??= $emails[0] ?? null;

        return $this->renderMailIndex([
            'mailboxes' => $mailboxes,
            'activeMailbox' => $activeMailbox,
            'folders' => $folders,
            'activeFolder' => $activeFolder,
            'emails' => $emails,
            'activeEmail' => $activeEmail,
            'folderEmailCounts' => $folderEmailCounts,
            'firstFolderByMailbox' => $firstFolderByMailbox,
            'totalEmails' => $totalEmails,
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
            'activeMailboxId' => $request->query->get('mailboxId'),
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

    #[Route(path: '/folder/{folderId}/more', name: 'mail_folder_more', requirements: ['folderId' => '[0-9a-fA-F\-]{36}'])]
    public function folderMore(string $folderId, Request $request): Response
    {
        $folder = $this->folderRepo->find($folderId);
        if (!$folder) {
            return new Response($this->translator->trans('folder.not_found'), Response::HTTP_NOT_FOUND);
        }
        $offset = (int) $request->query->get('offset', '0');
        $limit = (int) $request->query->get('limit', (string) self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        if ($offset < 0) {
            $offset = 0;
        }
        $mailboxId = $request->query->get('mailboxId');
        $activeMailbox = 'all' === $mailboxId ? null : $this->mailboxRepo->find($mailboxId);

        if ('all' === $mailboxId) {
            $emails = $this->mailService->collectEmails(
                $this->mailService->foldersInSameGroup(
                    $this->folderRepo->findFoldersLinkedToMailboxes($this->mailboxesForUser()),
                    $folder,
                ),
            );
        } else {
            $emails = $this->mailService->collectEmails([$folder], false);
        }
        $emails = $this->mailService->sortByDateDescending($emails);
        $slice = array_slice($emails, $offset, $limit);
        $html = $this->renderView('mail/_email_items.html.twig', [
            'emails' => $slice,
            'activeEmail' => null,
            'activeMailbox' => $activeMailbox,
            'activeFolder' => $folder,
        ]);

        return new Response($html);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderMailIndex(array $params): Response
    {
        return $this->render('mail/index.html.twig', array_merge([
            'mailboxes' => [],
            'activeMailbox' => null,
            'folders' => [],
            'activeFolder' => null,
            'emails' => [],
            'activeEmail' => null,
            'folderEmailCounts' => [],
            'firstFolderByMailbox' => [],
            'totalEmails' => 0,
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

        $mailbox = $email->getMailbox();
        if (null === $mailbox) {
            throw $this->createAccessDeniedException();
        }

        foreach ($user->getMailboxes() as $allowedMailbox) {
            if ($allowedMailbox === $mailbox) {
                return;
            }
        }

        throw $this->createAccessDeniedException();
    }

    /**
     * Mailboxes visible to the current user. Users with the admin role see
     * every mailbox, all other users only the mailboxes linked to them.
     *
     * @return array<Mailbox>
     */
    private function mailboxesForUser(): array
    {
        $user = $this->getUser();

        if ($user instanceof User && !in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $user->getMailboxes()->toArray();
        }

        return $this->mailboxRepo->findBy([], ['name' => 'ASC']);
    }
}

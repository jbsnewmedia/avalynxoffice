<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Form\BackupCreateType;
use AvalynxOffice\Service\Backup\BackupArchive;
use AvalynxOffice\Service\Backup\BackupRestoreService;
use AvalynxOffice\Service\Backup\BackupService;
use AvalynxOffice\Util\AvalynxFormResponse;
use AvalynxOffice\Util\FormInvalidMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin area "Backup": create, download, upload and restore whole
 * installation backups (database + attachment files), optionally encrypted
 * with a password (XChaCha20-Poly1305 via ext-sodium, Argon2id key
 * derivation). Backups live below data/backups, outside the web root.
 *
 * Uploads run as a chunked AJAX protocol so archive size is not capped by
 * the PHP upload limits: the browser sends the file in small pieces
 * (init → chunk … → finish), the server appends them below data/backups.
 * The total upload size is limited to 50 % of the free disk space of the
 * backup volume.
 */
#[Route('/admin/backup')]
#[IsGranted('ROLE_ADMIN')]
final class AdminBackupController extends AbstractController
{
    private const int MIN_PASSWORD_LENGTH = 8;
    private const int UPLOAD_CHUNK_BYTES = 8 * 1024 * 1024;
    private const float UPLOAD_DISK_RATIO = 0.5;
    private const int UPLOAD_STALE_SECONDS = 21600; // 6 h
    private const string UPLOAD_ID_PATTERN = '/^[0-9a-f]{32}$/';

    public function __construct(
        private readonly BackupService $backups,
        private readonly BackupArchive $archive,
        private readonly BackupRestoreService $restoreService,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $adminBackupCreateLimiter,
        private readonly RateLimiterFactoryInterface $adminBackupRestoreLimiter,
        private readonly RateLimiterFactoryInterface $adminBackupUploadLimiter,
    ) {
    }

    #[Route('', name: 'app_admin_backup', methods: ['GET'])]
    public function index(): Response
    {
        $form = $this->createForm(BackupCreateType::class, null, [
            'action' => $this->generateUrl('app_admin_backup_create'),
        ]);

        $limit = $this->uploadLimitBytes();

        return $this->render('admin/backup.html.twig', [
            'form' => $form,
            'backups' => $this->backups->listBackups(),
            'uploadMaxBytes' => $limit ?? 0,
            'uploadMaxText' => self::formatBytes($limit ?? 0),
            'uploadChunkSize' => self::UPLOAD_CHUNK_BYTES,
        ]);
    }

    #[Route('/create', name: 'app_admin_backup_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedException();
        }

        if (!$this->adminBackupCreateLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->tooManyRequests('admin.backup.err_rate_limit_create');
        }

        $form = $this->createForm(BackupCreateType::class, null, [
            'action' => $this->generateUrl('app_admin_backup_create'),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
        }

        $includeFiles = (bool) $form->get('includeFiles')->getData();
        $encrypt = (bool) $form->get('encrypt')->getData();
        $passwordData = $form->get('password')->getData();
        $passwordRepeatData = $form->get('passwordRepeat')->getData();
        $password = is_string($passwordData) ? $passwordData : '';
        $passwordRepeat = is_string($passwordRepeatData) ? $passwordRepeatData : '';

        if ($encrypt) {
            $validationError = $this->validateBackupPassword($password, $passwordRepeat);
            if (null !== $validationError) {
                $form->get($validationError[0])->addError(new FormError($this->translator->trans($validationError[1])));

                return AvalynxFormResponse::invalid(FormInvalidMapper::map($form));
            }
        }

        try {
            $filename = $this->backups->create($includeFiles, $encrypt ? $password : null);
        } catch (\RuntimeException) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_create_failed'));
        }

        $this->addFlash('success', $this->translator->trans('admin.backup.created', ['%filename%' => $filename]));

        return AvalynxFormResponse::success($this->generateUrl('app_admin_backup'));
    }

    #[Route('/download/{filename}', name: 'app_admin_backup_download', methods: ['GET'])]
    public function download(string $filename): BinaryFileResponse
    {
        try {
            $path = $this->backups->resolveFilename($filename);
        } catch (\RuntimeException) {
            throw new NotFoundHttpException('Backup not found.');
        }

        return $this->file($path, $filename);
    }

    /**
     * Starts a chunked upload: validates the announced size against the
     * 50 %-of-free-disk limit and creates an upload session below
     * data/backups (".upload-<id>.part" + ".upload-<id>.json").
     */
    #[Route('/upload/init', name: 'app_admin_backup_upload_init', methods: ['POST'])]
    public function uploadInit(Request $request): Response
    {
        if (!$this->allowUploadAccess($request)) {
            return AvalynxFormResponse::failure($this->translator->trans('common.invalid_token'));
        }

        if (!$this->adminBackupUploadLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->tooManyRequests('admin.backup.err_rate_limit_upload');
        }

        if (!$this->backupDirReady()) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_unavailable'));
        }

        $this->cleanupStaleUploads();

        $limit = $this->uploadLimitBytes();
        if (null === $limit) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_unavailable'));
        }

        $totalSizeRaw = $request->request->get('totalSize');
        $totalSize = is_int($totalSizeRaw) ? $totalSizeRaw : (is_string($totalSizeRaw) && 1 === preg_match('/^\d+$/', $totalSizeRaw) ? (int) $totalSizeRaw : -1);
        if ($totalSize < 1) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_empty'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($totalSize > $limit) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_too_large', [
                '%limit%' => self::formatBytes($limit),
            ]), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $uploadId = bin2hex(random_bytes(16));
        $paths = $this->uploadSessionPaths($uploadId);
        \assert(null !== $paths);

        if (false === file_put_contents($paths['part'], '')) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_unavailable'));
        }

        $this->saveUploadMeta($paths['meta'], [
            'totalSize' => $totalSize,
            'received' => 0,
            'limit' => $limit,
            'chunks' => 0,
        ]);

        return AvalynxFormResponse::success(null, [
            'uploadId' => $uploadId,
            'chunkSize' => self::UPLOAD_CHUNK_BYTES,
        ]);
    }

    /**
     * Receives one chunk of a running upload (raw octet-stream body,
     * uploadId/index as query parameters). Chunks must arrive strictly in
     * order; every chunk re-checks the size limit.
     */
    #[Route('/upload/chunk', name: 'app_admin_backup_upload_chunk', methods: ['POST'])]
    public function uploadChunk(Request $request): Response
    {
        if (!$this->allowUploadAccess($request)) {
            return AvalynxFormResponse::failure($this->translator->trans('common.invalid_token'));
        }

        $paths = $this->uploadSessionPaths((string) $request->query->get('uploadId', ''));
        if (null === $paths || !is_file($paths['meta'])) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_unknown'), Response::HTTP_NOT_FOUND);
        }

        $meta = $this->loadUploadMeta($paths['meta']);
        if (null === $meta) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_unknown'), Response::HTTP_NOT_FOUND);
        }

        $indexRaw = $request->query->get('index');
        $index = is_int($indexRaw) ? $indexRaw : (is_string($indexRaw) && 1 === preg_match('/^\d+$/', $indexRaw) ? (int) $indexRaw : -1);
        if ($index !== $meta['chunks']) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_order'), Response::HTTP_CONFLICT);
        }

        $chunk = $request->getContent();
        if (false === $chunk || '' === $chunk) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_empty'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $received = $meta['received'] + strlen($chunk);
        if ($received > $meta['totalSize'] || $received > $meta['limit']) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_too_large', [
                '%limit%' => self::formatBytes($meta['limit']),
            ]), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (false === file_put_contents($paths['part'], $chunk, FILE_APPEND)) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_unavailable'));
        }

        $meta['received'] = $received;
        ++$meta['chunks'];
        $this->saveUploadMeta($paths['meta'], $meta);

        return AvalynxFormResponse::success(null, ['received' => $received, 'total' => $meta['totalSize']]);
    }

    /**
     * Finishes a chunked upload: verifies the complete size, validates the
     * archive (plain archives must carry a valid manifest) and moves it to
     * its final generated name below data/backups.
     */
    #[Route('/upload/finish', name: 'app_admin_backup_upload_finish', methods: ['POST'])]
    public function uploadFinish(Request $request): Response
    {
        if (!$this->allowUploadAccess($request)) {
            return AvalynxFormResponse::failure($this->translator->trans('common.invalid_token'));
        }

        $paths = $this->uploadSessionPaths((string) $request->request->get('uploadId', ''));
        if (null === $paths || !is_file($paths['meta'])) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_unknown'), Response::HTTP_NOT_FOUND);
        }

        $meta = $this->loadUploadMeta($paths['meta']);
        if (null === $meta) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_unknown'), Response::HTTP_NOT_FOUND);
        }

        $partSize = filesize($paths['part']);
        if (false === $partSize || $partSize !== $meta['totalSize']) {
            $this->discardUpload($paths['part'], $paths['meta']);

            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_incomplete'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $encrypted = $this->archive->isEncrypted($paths['part']);
        if (!$encrypted) {
            try {
                $this->archive->readManifest($paths['part']);
            } catch (\RuntimeException) {
                $this->discardUpload($paths['part'], $paths['meta']);

                return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_invalid'));
            }
        }

        $filename = $this->backups->buildFilename('uploaded', $encrypted);
        if (!rename($paths['part'], $this->backups->getBackupDir().'/'.$filename)) {
            $this->discardUpload($paths['part'], $paths['meta']);

            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_upload_unavailable'));
        }

        @unlink($paths['meta']);

        $this->addFlash('success', $this->translator->trans('admin.backup.uploaded', ['%filename%' => $filename]));

        return AvalynxFormResponse::success($this->generateUrl('app_admin_backup'));
    }

    #[Route('/restore', name: 'app_admin_backup_restore', methods: ['POST'])]
    public function restore(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedException();
        }

        $token = $request->request->get('_token');
        if (null === $token || !$this->isCsrfTokenValid('admin_backup_restore', (string) $token)) {
            return AvalynxFormResponse::failure($this->translator->trans('common.invalid_token'));
        }

        if (!$this->adminBackupRestoreLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->tooManyRequests('admin.backup.err_rate_limit_restore');
        }

        $filename = (string) $request->request->get('restoreFilename', '');
        if ('' === $filename) {
            return AvalynxFormResponse::invalid(['restoreFilename' => $this->translator->trans('admin.backup.err_select_required')]);
        }

        try {
            $path = $this->backups->resolveFilename($filename);
        } catch (\RuntimeException) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_not_found'), Response::HTTP_NOT_FOUND);
        }

        $password = (string) $request->request->get('restorePassword', '');
        $encrypted = $this->archive->isEncrypted($path);
        if ($encrypted && '' === $password) {
            return AvalynxFormResponse::invalid([
                'restorePassword' => $this->translator->trans('admin.backup.err_password_required'),
            ]);
        }

        try {
            $result = $this->restoreService->restore($path, $encrypted ? $password : null);
        } catch (\RuntimeException $exception) {
            $messageKey = str_contains($exception->getMessage(), 'wrong password')
                ? 'admin.backup.err_wrong_password'
                : 'admin.backup.err_restore_failed';

            return AvalynxFormResponse::failure($this->translator->trans($messageKey));
        }

        $this->addFlash('success', $this->translator->trans('admin.backup.restored', [
            '%tableCount%' => (string) $result->tableCount,
            '%fileCount%' => (string) $result->fileCount,
        ]));

        return AvalynxFormResponse::success($this->generateUrl('app_admin_backup'));
    }

    #[Route('/delete/{filename}', name: 'app_admin_backup_delete', methods: ['POST'])]
    public function delete(string $filename, Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedException();
        }

        $token = $request->request->get('_token');
        if (null === $token || !$this->isCsrfTokenValid('admin_backup_delete', (string) $token)) {
            return AvalynxFormResponse::failure($this->translator->trans('common.invalid_token'));
        }

        try {
            $this->backups->delete($filename);
        } catch (\RuntimeException) {
            return AvalynxFormResponse::failure($this->translator->trans('admin.backup.err_delete_failed'), Response::HTTP_NOT_FOUND);
        }

        $this->addFlash('success', $this->translator->trans('admin.backup.deleted', ['%filename%' => $filename]));

        return AvalynxFormResponse::success($this->generateUrl('app_admin_backup'));
    }

    /**
     * Shared access guard for the chunked upload endpoints: XHR only and
     * the CSRF token in the X-CSRF-Token header (the raw chunk body cannot
     * carry a form field).
     */
    private function allowUploadAccess(Request $request): bool
    {
        if (!$request->isXmlHttpRequest()) {
            throw new AccessDeniedException();
        }

        $token = $request->headers->get('X-CSRF-Token');

        return null !== $token && $this->isCsrfTokenValid('admin_backup_upload', $token);
    }

    /**
     * Maximum upload size: 50 % of the free disk space of the backup
     * volume, null when the free space cannot be determined.
     */
    private function uploadLimitBytes(): ?int
    {
        if (!$this->backupDirReady()) {
            return null;
        }

        $free = disk_free_space($this->backups->getBackupDir());

        return is_float($free) ? (int) floor($free * self::UPLOAD_DISK_RATIO) : null;
    }

    private function backupDirReady(): bool
    {
        $dir = $this->backups->getBackupDir();

        return is_dir($dir) || mkdir($dir, 0o770, true) || is_dir($dir);
    }

    /**
     * Validates an upload id and resolves both session file paths.
     *
     * @return array{part: string, meta: string}|null
     */
    private function uploadSessionPaths(string $uploadId): ?array
    {
        if (1 !== preg_match(self::UPLOAD_ID_PATTERN, $uploadId)) {
            return null;
        }

        $dir = $this->backups->getBackupDir();

        return [
            'part' => $dir.'/.upload-'.$uploadId.'.part',
            'meta' => $dir.'/.upload-'.$uploadId.'.json',
        ];
    }

    /**
     * @return array{totalSize: int, received: int, limit: int, chunks: int}|null
     */
    private function loadUploadMeta(string $metaPath): ?array
    {
        $raw = file_get_contents($metaPath);
        if (false === $raw) {
            return null;
        }

        try {
            $meta = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($meta) || !isset($meta['totalSize'], $meta['received'], $meta['limit'], $meta['chunks'])
            || !is_int($meta['totalSize']) || !is_int($meta['received']) || !is_int($meta['limit']) || !is_int($meta['chunks'])
            || $meta['totalSize'] < 1 || $meta['received'] < 0 || $meta['chunks'] < 0) {
            return null;
        }

        return [
            'totalSize' => $meta['totalSize'],
            'received' => $meta['received'],
            'limit' => $meta['limit'],
            'chunks' => $meta['chunks'],
        ];
    }

    /**
     * @param array{totalSize: int, received: int, limit: int, chunks: int} $meta
     */
    private function saveUploadMeta(string $metaPath, array $meta): void
    {
        file_put_contents($metaPath, json_encode($meta, JSON_THROW_ON_ERROR));
    }

    private function discardUpload(string $partPath, string $metaPath): void
    {
        @unlink($partPath);
        @unlink($metaPath);
    }

    /**
     * Removes unfinished upload sessions older than 6 hours (abandoned
     * browser tabs leave .part/.json pairs behind).
     */
    private function cleanupStaleUploads(): void
    {
        $staleBefore = time() - self::UPLOAD_STALE_SECONDS;
        foreach (glob($this->backups->getBackupDir().'/.upload-*.json') ?: [] as $metaFile) {
            $mtime = filemtime($metaFile);
            if (false === $mtime || $mtime >= $staleBefore) {
                continue;
            }

            $partFile = substr($metaFile, 0, -5).'.part';
            @unlink($metaFile);
            @unlink($partFile);
        }
    }

    /**
     * @return array{0: string, 1: string}|null form field name and error message key
     */
    private function validateBackupPassword(string $password, string $passwordRepeat): ?array
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return ['password', 'admin.backup.err_password_min'];
        }

        if ($password !== $passwordRepeat) {
            return ['passwordRepeat', 'admin.backup.err_password_mismatch'];
        }

        return null;
    }

    private function tooManyRequests(string $messageKey): Response
    {
        $response = AvalynxFormResponse::failure($this->translator->trans($messageKey));
        $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);

        return $response;
    }

    /**
     * Human readable byte size, e.g. "100 MiB".
     */
    private static function formatBytes(int|float $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        foreach (['KiB', 'MiB', 'GiB'] as $unit) {
            $bytes /= 1024;
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }
        }

        return round($bytes, 1).' TiB';
    }
}

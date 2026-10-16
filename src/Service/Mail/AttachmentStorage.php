<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Attachment;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class AttachmentStorage
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $storageDir,
        private readonly int $segmentLength,
        private readonly int $segmentDepth,
        private readonly int $maxBytes,
    ) {
    }

    public function getBaseDir(): string
    {
        if ($this->isAbsolutePath($this->storageDir)) {
            return rtrim($this->storageDir, '/');
        }

        return rtrim($this->projectDir, '/').'/'.ltrim($this->storageDir, '/');
    }

    public function getMaxBytes(): int
    {
        return $this->maxBytes;
    }

    public function store(Attachment $attachment, string $data): string
    {
        $size = strlen($data);
        if ($size > $this->maxBytes) {
            throw new \RuntimeException(sprintf('Maximum of %d bytes allowed, got %d bytes.', $this->maxBytes, $size));
        }

        $relativePath = $this->buildRelativePath($attachment);
        $absolutePath = $this->resolveAbsolutePath($relativePath);

        try {
            new Filesystem()->dumpFile($absolutePath, $data);
        } catch (IOExceptionInterface $exception) {
            throw new \RuntimeException(sprintf('Attachment file "%s" could not be written.', $absolutePath), 0, $exception);
        }

        return $relativePath;
    }

    public function createDownloadResponse(Attachment $attachment): BinaryFileResponse
    {
        $absolutePath = $this->resolveAbsolutePath($attachment->getFilePath());
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new \RuntimeException(sprintf('Attachment file "%s" is not readable.', $absolutePath));
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getName());
        $response->headers->set('Content-Type', $attachment->getMimeType() ?: 'application/octet-stream');
        $response->headers->set('Cache-Control', 'private');

        return $response;
    }

    public function resolveAbsolutePath(string $filePath): string
    {
        if ('' === trim($filePath)) {
            throw new \RuntimeException('Attachment-Pfad darf nicht leer sein.');
        }

        if ($this->isAbsolutePath($filePath)) {
            return $filePath;
        }

        return rtrim($this->getBaseDir(), '/').'/'.ltrim($filePath, '/');
    }

    private function buildRelativePath(Attachment $attachment): string
    {
        $uuid = $this->normalizeUuid($attachment);
        $segments = [];

        for ($level = 0; $level < max(1, $this->segmentDepth); ++$level) {
            $offset = $level * max(1, $this->segmentLength);
            $segment = substr($uuid, $offset, max(1, $this->segmentLength));
            if ('' === $segment) {
                break;
            }

            $segments[] = $segment;
        }

        $extension = $this->normalizeExtension($attachment->getName());
        $segments[] = '' === $extension ? $uuid : sprintf('%s.%s', $uuid, $extension);

        return implode('/', $segments);
    }

    private function normalizeUuid(Attachment $attachment): string
    {
        $id = $attachment->getId();
        if (null === $id) {
            throw new \RuntimeException('Attachment-ID fehlt.');
        }

        return strtolower(str_replace('-', '', (string) $id));
    }

    private function normalizeExtension(string $fileName): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return substr(preg_replace('/[^a-z0-9]+/', '', $extension) ?? '', 0, 16);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || 1 === preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}

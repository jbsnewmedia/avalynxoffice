<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Twig;

use AvalynxOffice\Entity\Email;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Resolves "cid:" references (inline images) of an email's HTML body to
 * self-contained data URIs of the stored attachments. Data URIs render
 * inside the sandboxed mail iframe (opaque origin, no cookies) without
 * weakening the sandbox.
 */
final class EmailInlineImagesExtension extends AbstractExtension
{
    /**
     * Inline images larger than this are not inlined (the srcdoc would
     * grow unnecessarily); their cid reference stays untouched.
     */
    public const int MAX_BYTES = 512 * 1024;

    public function __construct(
        private readonly AttachmentStorage $attachmentStorage,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('email_inline_images', $this->inlineImages(...)),
        ];
    }

    public function inlineImages(string $html, Email $email): string
    {
        if ('' === $html || !str_contains($html, 'cid:')) {
            return $html;
        }

        foreach ($email->getEmailAttachments() as $link) {
            $attachment = $link->getAttachment();
            if (null === $attachment) {
                continue;
            }
            $contentId = $attachment->getContentId();
            if (null === $contentId || '' === $contentId) {
                continue;
            }
            $dataUri = $this->dataUri($attachment->getMimeType(), $attachment->getFilePath());
            if (null === $dataUri) {
                continue;
            }
            $html = str_ireplace('cid:'.$contentId, $dataUri, $html);
            $html = str_ireplace('cid:&lt;'.$contentId.'&gt;', $dataUri, $html);
        }

        return $html;
    }

    private function dataUri(?string $mimeType, ?string $filePath): ?string
    {
        if (null === $filePath || '' === $filePath) {
            return null;
        }
        try {
            $absolutePath = $this->attachmentStorage->resolveAbsolutePath($filePath);
        } catch (\Throwable) {
            return null;
        }
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }
        $size = (int) filesize($absolutePath);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return null;
        }
        $content = file_get_contents($absolutePath);
        if (false === $content) {
            return null;
        }
        $type = null !== $mimeType ? trim((string) explode(';', $mimeType)[0]) : '';
        $type = preg_match('#^[\w.+-]+/[\w.+-]+$#', $type) ? $type : 'application/octet-stream';

        return 'data:'.$type.';base64,'.base64_encode($content);
    }
}

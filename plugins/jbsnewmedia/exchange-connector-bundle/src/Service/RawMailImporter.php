<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\ExchangeConnectorBundle\Service;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\Body;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailAddressLink;
use AvalynxOffice\Entity\EmailAttachmentLink;
use AvalynxOffice\Entity\EmailBodyLink;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\BodyContentType;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailRoutingService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Laminas\Mail\Storage\Message as StorageMessage;

/**
 * Converts a raw RFC822 message (delivered by EWS as MimeContent) into the
 * local entity graph: Email, addresses, bodies, attachments and folder
 * assignment. The message is routed to an account via the connector aliases
 * (same logic as the POP3 connector).
 */
final class RawMailImporter
{
    private bool $lastParseNeededSanitizing = false;
    private readonly string $mailDumpDir;
    /** @var array<string, true> */
    private array $prunedDumpKeys = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmailRepository $emailRepo,
        private readonly FolderManager $folderManager,
        private readonly AttachmentStorage $attachmentStorage,
        private readonly MailRoutingService $mailRoutingService,
        ?string $mailDumpDir = null,
        private readonly int $mailDumpRetentionDays = 14,
    ) {
        $this->mailDumpDir = rtrim(null !== $mailDumpDir && '' !== $mailDumpDir ? $mailDumpDir : sys_get_temp_dir().'/avalynxoffice/mail_dumps', '/');
    }

    /**
     * @return array{status: string, reason: string|null, email: Email|null}
     *                                                                       status is one of "imported", "skipped", "error"
     */
    public function import(Connector $connector, string $importKey, string $rawContent): array
    {
        $message = $this->parseMessage($rawContent);
        if (!$message instanceof StorageMessage) {
            $this->dumpRaw($connector, $importKey, $rawContent, 'error', 'Header parsing failed');

            return ['status' => 'error', 'reason' => 'Header parsing failed', 'email' => null];
        }
        $hadEncodingIssues = $this->lastParseNeededSanitizing;
        $subject = $this->safeHeader($message, 'subject');
        $contentHash = hash('sha256', $rawContent);
        if ($this->emailRepo->findOneBy(['importKey' => $importKey]) instanceof Email) {
            return ['status' => 'skipped', 'reason' => 'already imported', 'email' => null];
        }
        if ($this->emailRepo->findOneBy(['contentHash' => $contentHash]) instanceof Email) {
            return ['status' => 'skipped', 'reason' => 'identical content already imported', 'email' => null];
        }
        $recipients = [];
        foreach (['to', 'cc'] as $header) {
            foreach ($this->parseAddresses($this->safeHeader($message, $header)) as $address) {
                $recipients[] = $address['email'];
            }
        }
        $target = $this->mailRoutingService->resolveTarget($connector, $recipients);
        if (null === $target) {
            $this->dumpRaw($connector, $importKey, $rawContent, 'error', 'No account found for routing');

            return ['status' => 'error', 'reason' => 'no account found for message routing', 'email' => null];
        }
        $account = $target['account'];
        $this->folderManager->ensureSystemFolder($account, FolderFunction::MAILBOX);
        $this->folderManager->ensureSystemFolder($account, FolderFunction::INBOX);
        $email = $this->convertMessageToEntity($message, $account, $importKey, $hadEncodingIssues);
        $email->setConnector($connector);
        $email->setMatchedLocalPart($target['alias']?->getLocalPart());
        $email->setContentHash($contentHash);
        $this->em->persist($email);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            if (!$this->em->isOpen()) {
                throw new \RuntimeException('EntityManager closed while importing message '.$importKey);
            }

            return ['status' => 'skipped', 'reason' => 'imported concurrently', 'email' => null];
        }
        $this->dumpRaw($connector, $importKey, $rawContent, 'success');

        return ['status' => 'imported', 'reason' => null, 'email' => $email];
    }

    /**
     * Parses a raw message. When the default parser fails on broken headers,
     * the header values are sanitized permissively (RFC 2047 encoding of raw
     * UTF-8 bytes) and parsed again.
     */
    private function parseMessage(string $rawContent): ?StorageMessage
    {
        $this->lastParseNeededSanitizing = false;
        try {
            return new StorageMessage(['raw' => $rawContent]);
        } catch (\Throwable) {
        }
        $splitPos = strpos($rawContent, "\r\n\r\n");
        if (false === $splitPos) {
            $splitPos = strpos($rawContent, "\n\n");
        }
        if (false === $splitPos) {
            return null;
        }
        $sanitized = $this->sanitizeRawHeaders(substr($rawContent, 0, $splitPos));
        $body = ltrim(substr($rawContent, $splitPos), "\r\n");
        try {
            $message = new StorageMessage(['headers' => $sanitized, 'content' => $body, 'noToplines' => true]);
            $this->lastParseNeededSanitizing = true;

            return $message;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sanitizeRawHeaders(string $headersRaw): string
    {
        $lines = preg_split('/\r\n|\n/', $headersRaw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            if ('' === $line || !preg_match('/^([!-9;-~]+):(.*)$/', $line, $m)) {
                $out[] = $line;

                continue;
            }
            $name = $m[1];
            $value = $m[2];
            if (preg_match('/[\x80-\xFF]/', $value)) {
                if (preg_match('/^(.*<[^>]+>)\s*$/', $value, $addrMatch)) {
                    $prefix = $addrMatch[1];
                    $angleStart = strrpos($prefix, '<');
                    if (false !== $angleStart) {
                        $displayName = trim(substr($prefix, 0, $angleStart));
                        $address = substr($prefix, $angleStart);
                        if (preg_match('/[\x80-\xFF]/', $displayName)) {
                            $displayName = $this->encodeUtf8As2047($displayName);
                        }
                        $value = $displayName.' '.$address;
                    }
                } else {
                    $value = $this->encodeUtf8As2047($value);
                }
            }
            $out[] = $name.':'.$value;
        }

        return implode("\r\n", $out);
    }

    private function encodeUtf8As2047(string $value): string
    {
        $cleaned = ltrim($value);
        if (!mb_check_encoding($cleaned, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $cleaned);
            $cleaned = is_string($converted) ? $converted : '';
        }

        return '=?utf-8?B?'.base64_encode($cleaned).'?=';
    }

    private function convertMessageToEntity(StorageMessage $msg, Account $account, string $importKey, bool $hasEncodingIssues): Email
    {
        $email = new Email();
        $email->setAccount($account);
        $email->setIsOutgoing(false)->setIsRead(false);
        $email->setImportKey($importKey);
        $email->setHasEncodingIssues($hasEncodingIssues);
        $email->setSubject($this->safeHeader($msg, 'subject'));
        $dateHeader = $this->safeHeader($msg, 'date');
        if (null !== $dateHeader) {
            try {
                $email->setReceivedAt(new \DateTimeImmutable($dateHeader));
            } catch (\Throwable) {
            }
        }
        $this->folderManager->syncEmailSystemFolders($email);
        $messageId = $this->safeHeader($msg, 'message-id');
        if (null !== $messageId) {
            $email->setMessageId(trim($messageId, "<> \t\r\n"));
        }
        foreach ($this->parseAddresses($this->safeHeader($msg, 'from')) as $a) {
            $this->linkAddress($email, $a['email'], $a['name'] ?? null, EmailAddressType::SENDER);
        }
        foreach (['to' => EmailAddressType::RECIPIENT, 'cc' => EmailAddressType::CC, 'bcc' => EmailAddressType::BCC, 'reply-to' => EmailAddressType::REPLY_TO] as $hdr => $type) {
            foreach ($this->parseAddresses($this->safeHeader($msg, $hdr)) as $a) {
                $this->linkAddress($email, $a['email'], $a['name'] ?? null, $type);
            }
        }
        $textBody = '';
        $htmlBody = '';
        $this->extractParts($msg, $textBody, $htmlBody, $email);
        if ('' !== $htmlBody) {
            $b = new Body();
            $b->setContent($htmlBody);
            $this->em->persist($b);
            $bl = new EmailBodyLink();
            $bl->setEmail($email);
            $bl->setBody($b);
            $bl->setContentType(BodyContentType::HTML);
            $email->addEmailBody($bl);
            $this->em->persist($bl);
            if (null === $email->getPreviewBody()) {
                $email->setPreviewBody($this->convertHtmlToText($htmlBody));
            }
        }
        if ('' !== $textBody) {
            $b = new Body();
            $b->setContent($textBody);
            $this->em->persist($b);
            $bl = new EmailBodyLink();
            $bl->setEmail($email);
            $bl->setBody($b);
            $bl->setContentType(BodyContentType::TEXT);
            $email->addEmailBody($bl);
            $this->em->persist($bl);
            $email->setPreviewBody(mb_substr($textBody, 0, 500));
        }
        $folder = $account->getInboxFolder() ?? $this->folderManager->ensureSystemFolder($account, FolderFunction::MAILBOX);
        $ef = new EmailFolder();
        $ef->setEmail($email);
        $ef->setFolder($folder);
        $email->addEmailFolder($ef);
        $this->em->persist($ef);

        return $email;
    }

    private function convertHtmlToText(string $html): string
    {
        $html = preg_replace('/<(head|style|script)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = preg_replace('/<(br|p|div|tr|td|h[1-6])\b[^>]*>/i', ' ', (string) $html);
        $text = strip_tags((string) $html);
        $text = preg_replace('/\s+/', ' ', $text);

        return mb_substr(trim((string) $text), 0, 500);
    }

    private function safeHeader(StorageMessage $msg, string $name): ?string
    {
        try {
            $headers = $msg->getHeaders();
            if (!$headers->has($name)) {
                return null;
            }
            $header = $headers->get($name);
            $value = '';
            if ($header instanceof \Laminas\Mail\Header\HeaderInterface) {
                $value = $header->getFieldValue();
            }

            return $this->toUtf8($this->decodeHeaderValue((string) $value));
        } catch (\Throwable) {
            return null;
        }
    }

    private function decodeHeaderValue(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }
        $value = preg_replace('/(\?=)\s+(?==\?)/', '$1', (string) $value);
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode((string) $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (false !== $decoded) {
                return $decoded;
            }
        }

        return mb_decode_mimeheader((string) $value);
    }

    private function toUtf8(?string $str): string
    {
        if (null === $str || '' === $str) {
            return '';
        }
        if (mb_check_encoding($str, 'UTF-8')) {
            return $str;
        }
        $encodings = ['ISO-8859-1', 'Windows-1252', 'ASCII', 'UTF-7'];
        $detected = mb_detect_encoding($str, $encodings, true);
        if (is_string($detected)) {
            $converted = mb_convert_encoding($str, 'UTF-8', $detected);
            if (is_string($converted)) {
                return $converted;
            }
        }
        $out = @mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');

        return is_string($out) ? $out : '';
    }

    /**
     * @return array<int, array{email: string, name?: string|null}>
     */
    private function parseAddresses(?string $header): array
    {
        if (null === $header || '' === $header) {
            return [];
        }
        if (str_contains($header, '=?')) {
            $header = $this->decodeHeaderValue($header);
        }
        $parts = preg_split('/\s*,\s*/', $header) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            if (preg_match('/^(.*?)<([^>]+)>$/', $part, $m)) {
                $name = trim(trim($m[1], "\"' "));
                $out[] = ['email' => trim($m[2]), 'name' => '' !== $name ? $this->toUtf8($name) : null];
            } else {
                $out[] = ['email' => trim($part)];
            }
        }

        return $out;
    }

    private function linkAddress(Email $email, string $emailAddr, ?string $name, EmailAddressType $type): void
    {
        $emailAddr = trim($emailAddr, "<> \t\n\r");
        if ('' === $emailAddr) {
            return;
        }
        $name = $this->toUtf8($name);
        $addrRepo = $this->em->getRepository(Address::class);
        $addr = $addrRepo->findOneBy(['email' => $emailAddr]);
        if (!$addr instanceof Address) {
            $addr = new Address();
            $addr->setEmail($emailAddr);
            $this->em->persist($addr);
        }
        if ('' !== $name && null === $addr->getName()) {
            $addr->setName($name);
        }
        $link = new EmailAddressLink();
        $link->setEmail($email);
        $link->setAddress($addr);
        $link->setType($type);
        $email->addEmailAddress($link);
        $this->em->persist($link);
    }

    private function extractParts(StorageMessage $msg, string &$textBody, string &$htmlBody, Email $email): void
    {
        if (!$msg->isMultipart()) {
            $type = strtolower($this->partContentType($msg));
            $content = $this->getPartContent($msg);
            if (str_contains($type, 'text/html')) {
                $htmlBody = $content;
            } else {
                $textBody = $content;
            }

            return;
        }
        try {
            foreach (new \RecursiveIteratorIterator($msg) as $part) {
                if (!$part instanceof StorageMessage) {
                    continue;
                }
                try {
                    $type = strtolower($this->partContentType($part));
                    if (str_contains($type, 'text/plain')) {
                        $textBody .= $this->getPartContent($part)."\n";
                    } elseif (str_contains($type, 'text/html')) {
                        $htmlBody .= $this->getPartContent($part)."\n";
                    } elseif (str_contains($type, 'message/delivery-status') || str_contains($type, 'message/rfc822') || str_contains($type, 'text/rfc822-headers')) {
                        $textBody .= "\n--- ".strtoupper($type)." ---\n".$this->getPartContent($part)."\n";
                    } elseif (str_contains($type, 'application/') || str_contains($type, 'image/') || str_contains($type, 'audio/') || str_contains($type, 'video/')) {
                        $this->storeAttachmentPart($part, $email, $type);
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        } catch (\Throwable) {
        }
    }

    private function partContentType(StorageMessage $part): string
    {
        try {
            $headers = $part->getHeaders();
            if (!$headers->has('content-type')) {
                return 'text/plain';
            }
            $header = $headers->get('content-type');
            if ($header instanceof \Laminas\Mail\Header\HeaderInterface) {
                return (string) $header->getFieldValue();
            }
        } catch (\Throwable) {
        }

        return 'text/plain';
    }

    private function getPartContent(StorageMessage $part, bool $isBinary = false): string
    {
        try {
            $content = (string) $part->getContent();
            $headers = $part->getHeaders();
            $encoding = 'plain';
            if ($headers->has('content-transfer-encoding')) {
                $header = $headers->get('content-transfer-encoding');
                if ($header instanceof \Laminas\Mail\Header\HeaderInterface) {
                    $encoding = strtolower((string) $header->getFieldValue());
                }
            }
            if ('base64' === $encoding) {
                $content = base64_decode($content, true) ?: '';
            } elseif ('quoted-printable' === $encoding) {
                $content = quoted_printable_decode($content);
            }
            if ($isBinary) {
                return $content;
            }
            if (!mb_check_encoding($content, 'UTF-8')) {
                $contentType = $this->partContentType($part);
                if (preg_match('/charset\s*=\s*"?([^";\s]+)"?/i', $contentType, $m)) {
                    $converted = @mb_convert_encoding($content, 'UTF-8', strtoupper($m[1]));
                    $content = is_string($converted) ? $converted : $this->toUtf8($content);
                } else {
                    $content = $this->toUtf8($content);
                }
            }

            return $content;
        } catch (\Throwable) {
            return '';
        }
    }

    private function storeAttachmentPart(StorageMessage $part, Email $email, string $contentType): void
    {
        $filename = self::normalizeAttachmentFilename($this->detectFileName($part));
        $data = $this->getPartContent($part, true);
        if ('' === $data) {
            return;
        }
        $att = new Attachment();
        $att->setName($filename);
        $att->setContentId($this->detectContentId($part));
        $att->setSize(strlen($data));
        $att->setMimeType($contentType ?: 'application/octet-stream');
        $att->setSha1(sha1($data));
        try {
            $att->setFilePath($this->attachmentStorage->store($att, $data));
        } catch (\RuntimeException) {
            return;
        }
        $this->em->persist($att);
        $link = new EmailAttachmentLink();
        $link->setEmail($email);
        $link->setAttachment($att);
        $email->addEmailAttachment($link);
        $this->em->persist($link);
    }

    /**
     * Content-ID of an inline image part (referenced by the HTML body via
     * "cid:<content id>"), without the angle brackets.
     */
    private function detectContentId(StorageMessage $part): ?string
    {
        try {
            $headers = $part->getHeaders();
            if (!$headers->has('content-id')) {
                return null;
            }
            $header = $headers->get('content-id');
            $value = '';
            if ($header instanceof \Laminas\Mail\Header\HeaderInterface) {
                $value = $header->getFieldValue();
            } elseif (is_string($header)) {
                $value = $header;
            }
            if ('' !== $value && preg_match('/<([^>]+)>/', $value, $m)) {
                return trim($m[1]);
            }
            if ('' !== $value) {
                return trim($value);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function detectFileName(StorageMessage $part): ?string
    {
        foreach (['content-disposition', 'content-type'] as $headerName) {
            try {
                $headers = $part->getHeaders();
                if (!$headers->has($headerName)) {
                    continue;
                }
                $header = $headers->get($headerName);
                $value = '';
                if ($header instanceof \Laminas\Mail\Header\HeaderInterface) {
                    $value = $header->getFieldValue();
                } elseif (is_string($header)) {
                    $value = $header;
                }
                if ('' !== $value && preg_match('/filename\s*=\s*"?([^";]+)"?/i', $value, $m)) {
                    return basename($this->toUtf8($this->decodeHeaderValue($m[1])));
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Normalizes an attachment file name: path components and control characters
     * are removed, the length is limited to 255 bytes, empty names fall back to
     * "attachment.bin".
     */
    public static function normalizeAttachmentFilename(?string $filename): string
    {
        if (null === $filename) {
            return 'attachment.bin';
        }
        $name = str_replace('\\', '/', $filename);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        }
        $name = trim($name, " .\t");
        if ('' === $name || '.' === $name || '..' === $name) {
            return 'attachment.bin';
        }
        if (strlen($name) > 255) {
            $ext = '';
            $dot = strrpos($name, '.');
            if (false !== $dot && strlen($name) - $dot <= 16) {
                $ext = substr($name, $dot);
                $name = substr($name, 0, $dot);
            }
            $name = mb_strcut($name, 0, 255 - strlen($ext)).$ext;
        }

        return $name;
    }

    /**
     * Builds a safe dump file name from an import key: only [A-Za-z0-9._-]
     * are kept and the length is capped at 120 characters (plus ".eml").
     */
    public static function dumpFileName(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $key) ?? '';
        $safe = trim($safe, '.');
        if ('' === $safe) {
            $safe = 'unknown';
        }

        return substr($safe, 0, 120).'.eml';
    }

    private function dumpRaw(Connector $connector, string $importKey, string $rawContent, string $status, ?string $errorReason = null): void
    {
        try {
            $connectorKey = self::connectorDumpKey($connector);
            $this->pruneOldDumps($connectorKey);
            $dir = $this->mailDumpDir.'/'.$connectorKey.'/'.new \DateTimeImmutable()->format('Y/m/d').'/'.$status;
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $prefix = 'success' === $status
                ? "X-Mail-Fetch-Status: success\nX-Mail-Fetch-Date: ".date('c')."\n\n"
                : 'X-Mail-Fetch-Status: error'."\n".'X-Mail-Fetch-Error: '.str_replace(["\r", "\n"], ' ', (string) $errorReason)."\n".'X-Mail-Fetch-Date: '.date('c')."\n\n";
            $filename = self::dumpFileName($importKey);
            file_put_contents($dir.'/'.$filename, $prefix.$rawContent);
        } catch (\Throwable) {
        }
    }

    /**
     * Deletes dump files older than the configured retention (in days) below the
     * connector's dump directory. Runs at most once per connector key per instance.
     */
    private function pruneOldDumps(string $connectorKey): void
    {
        if (isset($this->prunedDumpKeys[$connectorKey]) || $this->mailDumpRetentionDays <= 0) {
            return;
        }
        $this->prunedDumpKeys[$connectorKey] = true;
        $base = $this->mailDumpDir.'/'.$connectorKey;
        if (!is_dir($base)) {
            return;
        }
        $realBase = realpath($base);
        $realRoot = realpath($this->mailDumpDir);
        if (false === $realBase || false === $realRoot || !str_starts_with($realBase, rtrim($realRoot, '/').'/')) {
            return;
        }
        $threshold = time() - $this->mailDumpRetentionDays * 86400;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }
                if ($file->isFile() && $file->getMTime() < $threshold) {
                    @unlink($file->getPathname());
                } elseif ($file->isDir()) {
                    @rmdir($file->getPathname());
                }
            }
        } catch (\Throwable) {
        }
    }

    public static function connectorDumpKey(Connector $connector): string
    {
        $name = $connector->getDomain() ?: (string) $connector->getId();

        return self::normalizeDumpKey($name);
    }

    /**
     * Normalizes a connector dump key to a single safe directory name: only
     * [A-Za-z0-9@._-] are kept, leading/trailing dots are trimmed, ".", ".."
     * and empty values fall back to "unknown_connector", max. 120 characters.
     */
    public static function normalizeDumpKey(string $name): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9@._-]/', '_', $name) ?? '';
        $safe = trim($safe, '.');
        if ('' === $safe || '.' === $safe || '..' === $safe) {
            return 'unknown_connector';
        }

        return substr($safe, 0, 120);
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\Pop3ConnectorBundle\Service;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\Body;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\ConnectorConfig;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailAddressLink;
use AvalynxOffice\Entity\EmailAttachmentLink;
use AvalynxOffice\Entity\EmailBodyLink;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\BodyContentType;
use AvalynxOffice\Entity\Enum\EmailAddressType;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Plugin\Contract\ConnectorFetchRequest;
use AvalynxOffice\Repository\ConnectorConfigRepository;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailRoutingService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use JBSNewMedia\Pop3ConnectorBundle\Plugin\Pop3ConnectorPlugin;
use Laminas\Mail\Storage\Message as StorageMessage;
use Symfony\Component\Console\Output\OutputInterface;

class Pop3Fetcher
{
    private ?OutputInterface $output = null;
    private ?string $errorLogPath = null;
    private readonly string $mailDumpDir;
    /** @var array<string, true> */
    private array $prunedDumpKeys = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmailRepository $emailRepo,
        private readonly FolderManager $folderManager,
        private readonly AttachmentStorage $attachmentStorage,
        private readonly Pop3StorageFactory $pop3StorageFactory,
        private readonly MailRoutingService $mailRoutingService,
        private readonly ConnectorConfigRepository $connectorConfigRepository,
        ?string $mailDumpDir = null,
        private readonly int $mailDumpRetentionDays = 14,
    ) {
        $this->mailDumpDir = rtrim(null !== $mailDumpDir && '' !== $mailDumpDir ? $mailDumpDir : sys_get_temp_dir().'/avalynxoffice/mail_dumps', '/');
    }

    public function setOutput(?OutputInterface $output): self
    {
        $this->output = $output;

        return $this;
    }

    public function setErrorLogPath(?string $path): self
    {
        $this->errorLogPath = $path;

        return $this;
    }

    private function log(string $message, string $type = 'info'): void
    {
        if ($this->output) {
            $this->output->writeln(sprintf('<%s>   %s</%s>', $type, $message, $type));
        }
        if ('error' === $type && $this->errorLogPath) {
            $line = sprintf('[%s] %s', date('Y-m-d H:i:s'), $message);
            @file_put_contents($this->errorLogPath, $line."\n", FILE_APPEND);
        }
    }

    private function logError(int $num, ?string $uidl, ?string $subject, \Throwable $e, ?string $connectorKey = null, ?\Laminas\Mail\Storage\Pop3 $storage = null): void
    {
        $msg = sprintf('Error on message %d (UIDL: %s): %s — subject: %s', $num, $uidl ?? 'unknown', $e->getMessage(), $subject ?? '(no subject)');
        $this->log($msg, 'error');
        if ($this->errorLogPath) {
            $detail = sprintf(
                "[%s] === ERROR ===\nMessage no.: %d\nUIDL: %s\nSubject: %s\nError: %s\nFile: %s:%d\nTrace:\n%s\n\n",
                date('Y-m-d H:i:s'),
                $num,
                $uidl ?? 'unknown',
                $subject ?? '(no subject)',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );
            @file_put_contents($this->errorLogPath, $detail, FILE_APPEND);
        }
        if (null !== $connectorKey && $storage) {
            $this->dumpRawError($num, $uidl, $connectorKey, $storage, $e->getMessage());
        }
    }

    private function dumpRawError(int $num, ?string $uidl, string $connectorKey, \Laminas\Mail\Storage\Pop3 $storage, string $errorReason): void
    {
        $raw = $this->fetchRawViaProtocol($storage, $num);
        if (null === $raw) {
            $this->log(sprintf('Could not read raw content of message %d via Laminas API or POP3 RETR.', $num), 'error');

            return;
        }
        try {
            $date = new \DateTimeImmutable();
            $this->pruneOldDumps($connectorKey);
            $dir = $this->dumpDir($connectorKey, $date, 'error');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $filename = self::dumpFileName($uidl ?? (string) $num);
            $fullContent = "X-Mail-Fetch-Status: error\nX-Mail-Fetch-Error: ".str_replace(["\r", "\n"], ' ', $errorReason)."\nX-Mail-Fetch-Date: ".date('c')."\nX-Mail-Fetch-UIDL: ".($uidl ?? 'unknown')."\nX-Mail-Fetch-Num: ".$num."\n\n".$raw;
            file_put_contents($dir.'/'.$filename, $fullContent);
            $this->log(sprintf('Raw-Mail als error abgelegt: %s/%s', $dir, $filename), 'comment');
        } catch (\Throwable $dumpError) {
            $this->log('Error dumping the failed mail: '.$dumpError->getMessage(), 'error');
        }
    }

    private function fetchRawViaProtocol(\Laminas\Mail\Storage\Pop3 $storage, int $num): ?string
    {
        try {
            return $storage->getRawHeader($num)."\n\n".$storage->getRawContent($num);
        } catch (\Throwable) {
        }
        try {
            $reflection = new \ReflectionClass($storage);
            $protocolProp = $reflection->getProperty('protocol');
            $protocol = $protocolProp->getValue($storage);
            if (is_object($protocol) && method_exists($protocol, 'retrieve')) {
                $this->log(sprintf('Fallback: direct POP3 RETR for message %d (bypasses the Laminas header parser).', $num), 'comment');

                $raw = $protocol->retrieve($num);

                return is_string($raw) ? $raw : '';
            }
        } catch (\Throwable $e) {
            $this->log(sprintf('POP3 RETR fallback for message %d failed: %s', $num, $e->getMessage()), 'error');
        }

        return null;
    }

    /**
     * Versucht eine Raw-Mail (Header + Body) in ein Laminas StorageMessage zu parsen.
     * When the default parser fails on broken headers (e.g. raw UTF-8 bytes in To:/From:),
     * werden die Header-Werte permissiv via RFC 2047 encoded-word saniert und erneut geparst.
     */
    private function parseMessageWithSanitizedHeaders(string $rawContent): ?StorageMessage
    {
        try {
            return new StorageMessage(['raw' => $rawContent]);
        } catch (\Throwable $firstError) {
            $this->log(sprintf('First parser attempt failed (%s), trying header sanitization...', $firstError->getMessage()), 'comment');
        }

        $splitPos = strpos($rawContent, "\r\n\r\n");
        if (false === $splitPos) {
            $splitPos = strpos($rawContent, "\n\n");
        }
        if (false === $splitPos) {
            return null;
        }
        $headersRaw = substr($rawContent, 0, $splitPos);
        $body = substr($rawContent, $splitPos);
        $body = ltrim($body, "\r\n");

        $sanitized = $this->sanitizeRawHeaders($headersRaw);

        try {
            return new StorageMessage(['headers' => $sanitized, 'content' => $body, 'noToplines' => true]);
        } catch (\Throwable $secondError) {
            $this->log(sprintf('Sanitized headers also fail: %s', $secondError->getMessage()), 'error');

            return null;
        }
    }

    private function sanitizeRawHeaders(string $headersRaw): string
    {
        $lines = preg_split('/\r\n|\n/', $headersRaw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            if ('' === $line) {
                $out[] = $line;
                continue;
            }
            if (preg_match('/^([!-9;-~]+):(.*)$/', $line, $m)) {
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
            } else {
                $out[] = $line;
            }
        }

        return implode("\r\n", $out);
    }

    private function encodeUtf8As2047(string $value): string
    {
        $cleaned = ltrim($value);
        if (false === mb_check_encoding($cleaned, 'UTF-8')) {
            $detected = mb_detect_encoding($cleaned, ['Windows-1252', 'ISO-8859-1', 'UTF-8'], true);
            if ('UTF-8' !== $detected && false !== $detected) {
                $converted = mb_convert_encoding($cleaned, 'UTF-8', $detected);
                if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
                    $cleaned = $converted;
                } else {
                    $cleaned = (string) iconv('UTF-8', 'UTF-8//IGNORE', $cleaned);
                }
            } else {
                $cleaned = (string) iconv('UTF-8', 'UTF-8//IGNORE', $cleaned);
            }
        }

        return '=?utf-8?B?'.base64_encode($cleaned).'?=';
    }

    /**
     * @param array<string, mixed> $config stored plugin config of the connector
     *
     * @return array{imported: int, skipped: int, errors: int}
     */
    public function fetch(Connector $connector, array $config, ConnectorFetchRequest $request): array
    {
        $this->output = $request->output ?? $this->output;
        $this->errorLogPath = $request->errorLogPath ?? $this->errorLogPath;
        $host = self::stringValue($config, 'pop3Host');
        $username = self::stringValue($config, 'pop3Username');
        $password = self::stringValue($config, 'pop3Password');
        if ('' === $host || '' === $username || '' === $password) {
            $this->log('POP3 configuration incomplete.', 'error');

            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }
        try {
            $host = SmtpMailerFactory::validateHost($host);
        } catch (\InvalidArgumentException) {
            $this->log(sprintf('Invalid POP3 host "%s".', $host), 'error');

            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }
        $port = self::intValue($config, 'pop3Port');
        if (0 !== $port && ($port < 1 || $port > 65535)) {
            $this->log(sprintf('Invalid POP3 port %d.', $port), 'error');

            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }
        $security = strtolower(trim(self::stringValue($config, 'pop3Security')));
        if ('' === $security || 'none' === $security) {
            $this->log('POP3 connection without encryption (security not set) — credentials are transmitted in plain text.', 'comment');
        }
        $delete = $request->deleteAfter ?? self::boolValue($config, 'pop3DeleteAfterFetch', true);
        $fetchFrom = $connector->getFetchFrom();
        $this->log(sprintf('Verbinde mit %s:%d (Sicherheit: %s) ...', $host, $port ?: ('ssl' === $security ? 995 : 110), '' !== $security ? $security : 'keine'));
        $storageConfig = [
            'host' => $host,
            'user' => $username,
            'password' => $password,
            'port' => $port ?: null,
            'ssl' => null,
        ];
        if ('ssl' === $security || 'tls' === $security) {
            $storageConfig['ssl'] = 'ssl' === $security ? 'SSL' : 'TLS';
        }
        $connectorKey = $this->getConnectorDumpKey($connector, $username);
        $imported = $skipped = $errors = 0;
        try {
            $storage = $this->pop3StorageFactory->create($storageConfig);
            $this->log('Connection established successfully.');
        } catch (\Throwable $e) {
            $this->log(sprintf('Verbindungsfehler: %s', $e->getMessage()), 'error');

            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }
        try {
            $this->log('Listing messages on the server ...');
            $uidls = $storage->getUniqueId();
            if (!is_array($uidls)) {
                throw new \RuntimeException('Could not list the messages on the server.');
            }
            $count = count($uidls);
            $this->log(sprintf('%d messages found.', $count));

            // Diff the server messages against everything already imported:
            // known messages must never occupy the fetch window, otherwise
            // the sync would re-check the same messages forever.
            $known = $this->emailRepo->importKeysForConnector($connector);
            $connectorId = (string) $connector->getId();
            $candidates = [];
            foreach ($uidls as $num => $uidl) {
                if (!is_string($uidl) || '' === $uidl) {
                    continue;
                }
                $importKey = $connectorId.':'.$uidl;
                if (!isset($known[$importKey])) {
                    $candidates[(int) $num] = $importKey;
                }
            }
            if ($request->oldestFirst) {
                ksort($candidates);
            } else {
                krsort($candidates);
            }

            // With "fetch from" set, start at the date boundary (the day
            // itself counts) — messages below the boundary are never
            // imported, so they are dropped from the candidates entirely.
            if (null !== $fetchFrom) {
                $boundary = $this->findFetchFromBoundary($storage, $count, $fetchFrom);
                $this->log(sprintf('"Fetch from" is set (%s): skipping messages below message %d.', $fetchFrom->format('d.m.Y'), $boundary));
                $candidates = array_filter(
                    $candidates,
                    static fn (int $num): bool => $num >= $boundary,
                    ARRAY_FILTER_USE_KEY,
                );
            }

            $indices = array_slice(array_keys($candidates), 0, $request->limit);
            $this->log(sprintf('%d new messages to process.', count($indices)));
            $deleteNumbers = [];
            foreach ($indices as $num) {
                $uidl = is_string($uidls[$num] ?? null) ? $uidls[$num] : (string) $num;
                $subject = null;
                $hadEncodingIssues = false;
                try {
                    $importKey = (string) $connector->getId().':'.$uidl;
                    $this->log(sprintf('Checking message %d (UIDL: %s) ...', $num, $uidl), 'comment');
                    $exists = $this->emailRepo->findOneBy(['importKey' => $importKey]);
                    if ($exists) {
                        $this->log('Already imported. Skipping.');
                        ++$skipped;
                        continue;
                    }
                    $this->log('Loading message from server ...');
                    try {
                        $msg = $storage->getMessage($num);
                        $rawContent = $msg->getHeaders()->toString()."\n\n".$msg->getContent();
                    } catch (\Throwable $parseError) {
                        $this->log(sprintf('Laminas header parser fails (message %d): %s — trying header sanitization.', $num, $parseError->getMessage()), 'comment');
                        $raw = $this->fetchRawViaProtocol($storage, $num);
                        if (null === $raw) {
                            throw $parseError;
                        }
                        $parsedMsg = $this->parseMessageWithSanitizedHeaders($raw);
                        if (null === $parsedMsg) {
                            $this->dumpRawError($num, $uidl, $connectorKey, $storage, 'Header sanitization failed: '.$parseError->getMessage());
                            throw $parseError;
                        }
                        $msg = $parsedMsg;
                        $rawContent = $raw;
                        $hadEncodingIssues = true;
                        $this->log(sprintf('Header sanitization succeeded for message %d.', $num), 'info');
                    }
                    $contentHash = hash('sha256', $rawContent);
                    $existsByHash = $this->emailRepo->findOneBy(['contentHash' => $contentHash]);
                    if ($existsByHash) {
                        $this->log('Message with identical content already imported. Skipping.');
                        ++$skipped;
                        continue;
                    }
                    $subject = $this->safeHeader($msg, 'subject');
                    if (null !== $fetchFrom) {
                        $receivedAt = $this->extractDateFromMessage($msg);
                        if (null !== $receivedAt && $receivedAt < $fetchFrom) {
                            $this->log(sprintf('Message "%s" is older than the fetch-from date (%s). Skipping.', (string) $subject, $fetchFrom->format('d.m.Y')), 'comment');
                            ++$skipped;

                            continue;
                        }
                    }
                    $this->log(sprintf('Processing message: "%s" (hash: %s)', $subject, substr($contentHash, 0, 8)));
                    $recipients = $this->collectRecipientAddresses($msg);
                    $target = $this->mailRoutingService->resolveTarget($connector, $recipients);
                    if (null === $target) {
                        $this->log(sprintf('No account found for message "%s" (no alias match, no default account). Message stays on the server.', (string) $subject), 'error');
                        ++$errors;
                        continue;
                    }
                    $targetAccount = $target['account'];
                    $matchedLocalPart = $target['alias']?->getLocalPart();
                    $this->ensureInboxForAccount($targetAccount);
                    $this->log(sprintf('Routing message to account "%s"%s.', $targetAccount->getEmail(), null !== $matchedLocalPart ? sprintf(' (alias %s)', $matchedLocalPart) : ' (default account)'));
                    $this->dumpOriginalMessage($msg, $connectorKey, null, $importKey, 'pending');
                    try {
                        $email = $this->convertMessageToEntity($msg, $targetAccount, $importKey, $hadEncodingIssues);
                        $email->setConnector($connector);
                        $email->setMatchedLocalPart($matchedLocalPart);
                        $email->setContentHash($contentHash);
                        $this->log(sprintf('Speichere in Datenbank (ID: %s) ...', $email->getId()));
                        $this->em->persist($email);
                        try {
                            $this->em->flush();
                        } catch (UniqueConstraintViolationException $e) {
                            $this->log('Message was already imported by another process while running (unique constraint). Skipping.', 'comment');
                            if (!$this->em->isOpen()) {
                                throw $e;
                            }
                            continue;
                        }
                        $this->dumpOriginalMessage($msg, $connectorKey, $email, $importKey);
                    } catch (\Throwable $e) {
                        $this->removePendingDump($connectorKey, $importKey, $this->extractDateFromMessage($msg));
                        throw $e;
                    }
                    if ($delete) {
                        // Deferred: message numbers shift on deletion, so all
                        // deletions run after the loop, highest number first.
                        $deleteNumbers[] = $num;
                    }
                    $this->log('Message imported successfully.', 'info');
                    ++$imported;
                } catch (\Throwable $e) {
                    $this->logError($num, $uidl, $subject, $e, $connectorKey, $storage);
                    ++$errors;
                    if (!$this->em->isOpen()) {
                        $this->log('EntityManager is closed. Trying to re-open (warning: state may be lost).', 'error');
                        break;
                    }
                    $this->em->clear();
                    $connector = $this->em->find(Connector::class, $connector->getId()) ?? $connector;
                }
            }
            if ([] !== $deleteNumbers) {
                $this->log(sprintf('Deleting %d imported messages from the server ...', count($deleteNumbers)));
                rsort($deleteNumbers);
                foreach ($deleteNumbers as $num) {
                    $storage->removeMessage($num);
                }
            }
            $this->updateServerTotal($connector, $count, $delete, $imported);
        } finally {
            $storage->close();
            $this->log('Verbindung geschlossen.');
        }

        return compact('imported', 'skipped', 'errors');
    }

    /**
     * Updates the fetch progress in the stored plugin config: server_total
     * is the number of messages this connector has to have local in total
     * (messages still on the server plus everything already imported).
     * Works on the stored (encrypted) config so no decrypted secret is
     * written back.
     */
    private function updateServerTotal(Connector $connector, int $serverCountAtStart, bool $delete, int $imported): void
    {
        try {
            $serverAfter = max(0, $serverCountAtStart - ($delete ? $imported : 0));
            $local = (int) $this->emailRepo->count(['connector' => $connector]);
            $entity = $this->connectorConfigRepository->findOneBy(['connector' => $connector, 'pluginKey' => Pop3ConnectorPlugin::KEY]);
            if (!$entity instanceof ConnectorConfig) {
                return;
            }
            $stored = $entity->getConfig();
            $stored['server_total'] = $delete ? $local + $serverAfter : $serverAfter;
            $entity->setConfig($stored);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->log('Could not update the fetch status: '.$e->getMessage(), 'error');
        }
    }

    /**
     * POP3 has no server side search: locate the first message whose date
     * is at or after the fetch-from date via a binary search over the
     * (roughly chronologically ordered) message numbers, using cheap
     * header-only requests. The result may be slightly off for stragglers
     * with unusual dates; the per-message date check stays as a safety net.
     */
    private function findFetchFromBoundary(\Laminas\Mail\Storage\Pop3 $storage, int $count, \DateTimeImmutable $fetchFrom): int
    {
        $lo = 1;
        $hi = $count;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $date = $this->headerDate($storage, $mid);
            if (null !== $date && $date < $fetchFrom) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return max(1, $lo);
    }

    /**
     * Parses the Date header of one message via a header-only request
     * (POP3 TOP) without downloading the body.
     */
    private function headerDate(\Laminas\Mail\Storage\Pop3 $storage, int $num): ?\DateTimeImmutable
    {
        try {
            $rawHeader = $storage->getRawHeader($num);
            if (is_string($rawHeader) && preg_match('/^Date:\s*(.+)$/mi', $rawHeader, $m)) {
                try {
                    return new \DateTimeImmutable(trim((string) $m[1]));
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function ensureInboxForAccount(Account $account): void
    {
        $this->folderManager->ensureSystemFolder($account, FolderFunction::MAILBOX);
        $this->folderManager->ensureSystemFolder($account, FolderFunction::INBOX);
    }

    /**
     * Collects all To and Cc addresses of the message in header order
     * (To before Cc) for alias routing.
     *
     * @return list<string>
     */
    private function collectRecipientAddresses(StorageMessage $msg): array
    {
        $recipients = [];
        foreach (['to', 'cc'] as $header) {
            foreach ($this->parseAddresses($this->safeHeader($msg, $header)) as $address) {
                $recipients[] = $address['email'];
            }
        }

        return $recipients;
    }

    private function convertMessageToEntity(StorageMessage $msg, Account $account, string $importKey, bool $hasEncodingIssues = false): Email
    {
        $email = new Email();
        $email->setAccount($account);
        $email->setIsOutgoing(false)->setIsRead(false);
        $email->setImportKey($importKey);
        $email->setHasEncodingIssues($hasEncodingIssues);
        $email->setSubject($this->safeHeader($msg, 'subject'));
        $dateHeader = $this->safeHeader($msg, 'date');
        if ($dateHeader) {
            try {
                $email->setReceivedAt(new \DateTimeImmutable($dateHeader));
            } catch (\Throwable) {
            }
        }
        $this->folderManager->syncEmailSystemFolders($email);
        $messageId = $this->safeHeader($msg, 'message-id');
        if ($messageId) {
            $email->setMessageId(trim($messageId, "<> \t\r\n"));
        }
        $from = $this->parseAddresses($this->safeHeader($msg, 'from'));
        foreach ($from as $a) {
            $this->linkAddress($email, $a['email'], $a['name'] ?? null, EmailAddressType::SENDER);
        }
        foreach (['to' => EmailAddressType::RECIPIENT, 'cc' => EmailAddressType::CC, 'bcc' => EmailAddressType::BCC, 'reply-to' => EmailAddressType::REPLY_TO] as $hdr => $type) {
            $list = $this->parseAddresses($this->safeHeader($msg, $hdr));
            foreach ($list as $a) {
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
            $email->setPreviewBody(mb_substr((string) $textBody, 0, 500));
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
        $value = preg_replace('/(\?=)\s+(?==\?)/', '$1', $value);
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
            if (preg_match('/[\xC3\xC2][\x80-\xBF]/', $str)) {
                $tentative = @mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
                if (false !== $tentative && $tentative !== $str && mb_check_encoding($tentative, 'UTF-8')) {
                    return $tentative;
                }
            }

            return $str;
        }
        $encodings = ['ISO-8859-1', 'Windows-1252', 'ASCII', 'UTF-7'];
        $detected = mb_detect_encoding($str, $encodings, true);
        if ($detected) {
            $conv = mb_convert_encoding($str, 'UTF-8', $detected);
            if (is_string($conv)) {
                return $conv;
            }
        }
        $out = @mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');

        return (string) $out;
    }

    /**
     * @return array<int, array{email: string, name?: string|null}>
     */
    private function parseAddresses(?string $header): array
    {
        if (!$header) {
            return [];
        }
        if (str_contains($header, '=?')) {
            $header = $this->decodeHeaderValue($header);
        }
        $parts = preg_split('/\s*,\s*/', $header) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ('' === $p) {
                continue;
            }
            if (preg_match('/^(.*?)<([^>]+)>$/', $p, $m)) {
                $name = trim(trim($m[1], "\"' "));
                $out[] = ['email' => trim($m[2]), 'name' => '' !== $name ? $this->toUtf8($name) : null];
            } else {
                $out[] = ['email' => trim($p)];
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
        if (!$addr) {
            $addr = new Address();
            $addr->setEmail($emailAddr);
            $this->em->persist($addr);
        }
        if ('' !== $name && !$addr->getName()) {
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
        if ($msg->isMultipart()) {
            $partCount = 0;
            try {
                /** @var StorageMessage $part */
                foreach (new \RecursiveIteratorIterator($msg) as $part) {
                    ++$partCount;
                    try {
                        $headers = $part->getHeaders();
                        $typeHeader = $headers->has('content-type') ? $headers->get('content-type') : null;
                        $typeFieldValue = '';
                        if ($typeHeader instanceof \Laminas\Mail\Header\HeaderInterface) {
                            $typeFieldValue = $typeHeader->getFieldValue();
                        }
                        $rawType = $part->contentType ?? null;
                        $type = strtolower(is_string($rawType) ? $rawType : $typeFieldValue);
                        if (str_contains($type, 'text/plain')) {
                            $textBody .= $this->getPartContent($part)."\n";
                        } elseif (str_contains($type, 'text/html')) {
                            $htmlBody .= $this->getPartContent($part)."\n";
                        } elseif (str_contains($type, 'message/delivery-status') || str_contains($type, 'message/rfc822') || str_contains($type, 'text/rfc822-headers')) {
                            $textBody .= "\n--- ".strtoupper($type)." ---\n";
                            $textBody .= $this->getPartContent($part)."\n";
                        } elseif (str_contains($type, 'application/') || str_contains($type, 'image/') || str_contains($type, 'audio/') || str_contains($type, 'video/')) {
                            $this->log(sprintf('Processing attachment (type: %s) ...', $type));
                            $this->storeAttachmentPart($part, $email, $type);
                        }
                    } catch (\Throwable $e) {
                        $this->log(sprintf('Skipping failing MIME part %d: %s', $partCount, $e->getMessage()), 'comment');
                    }
                }
            } catch (\Throwable $e) {
                $this->log(sprintf('Error iterating the MIME parts: %s', $e->getMessage()), 'comment');
            }
            if ($partCount > 0) {
                $this->log(sprintf('%d MIME-Parts verarbeitet.', $partCount));
            }
        } else {
            $headers = $msg->getHeaders();
            $typeHeader = $headers->has('content-type') ? $headers->get('content-type') : null;
            $typeFieldValue = 'text/plain';
            if ($typeHeader instanceof \Laminas\Mail\Header\HeaderInterface) {
                $typeFieldValue = $typeHeader->getFieldValue();
            }
            $rawType = $msg->contentType ?? null;
            $type = strtolower(is_string($rawType) ? $rawType : $typeFieldValue);
            $content = $this->getPartContent($msg);
            if (str_contains($type, 'text/html')) {
                $htmlBody = $content;
            } else {
                $textBody = $content;
            }
        }
    }

    private function getPartContent(StorageMessage $part, bool $isBinary = false): string
    {
        try {
            $content = (string) $part->getContent();
            $headers = $part->getHeaders();
            $encodingHeader = $headers->has('content-transfer-encoding') ? $headers->get('content-transfer-encoding') : null;
            $encodingFieldValue = '';
            if ($encodingHeader instanceof \Laminas\Mail\Header\HeaderInterface) {
                $encodingFieldValue = $encodingHeader->getFieldValue();
            }
            $rawEncoding = $part->contentTransferEncoding ?? null;
            $encoding = strtolower(is_string($rawEncoding) ? $rawEncoding : $encodingFieldValue);
            if ('base64' === $encoding) {
                $content = base64_decode($content, true) ?: '';
            } elseif ('quoted-printable' === $encoding) {
                $content = quoted_printable_decode($content);
            }
            if ($isBinary) {
                return $content;
            }
            if (!mb_check_encoding($content, 'UTF-8')) {
                $typeHeader = $headers->has('content-type') ? $headers->get('content-type') : null;
                $typeFieldValue = '';
                if ($typeHeader instanceof \Laminas\Mail\Header\HeaderInterface) {
                    $typeFieldValue = $typeHeader->getFieldValue();
                }
                $rawContentType = $part->contentType ?? null;
                $contentType = is_string($rawContentType) ? $rawContentType : $typeFieldValue;
                if (preg_match('/charset\s*=\s*\"?([^\";\s]+)\"?/i', $contentType, $m)) {
                    $charset = strtoupper($m[1]);
                    try {
                        $converted = mb_convert_encoding($content, 'UTF-8', $charset);
                        $content = is_string($converted) ? $converted : $this->toUtf8($content);
                    } catch (\Throwable) {
                        $content = $this->toUtf8($content);
                    }
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
        $att = new Attachment();
        $att->setName($filename);
        $att->setContentId($this->detectContentId($part));
        $att->setSize(strlen($data));
        $att->setMimeType($contentType ?: 'application/octet-stream');
        $att->setSha1(sha1($data));
        try {
            $att->setFilePath($this->attachmentStorage->store($att, $data));
        } catch (\RuntimeException $e) {
            $this->log(sprintf('Skipping attachment "%s": %s', $filename, $e->getMessage()), 'comment');

            return;
        }
        $this->em->persist($att);
        $link = new EmailAttachmentLink();
        $link->setEmail($email);
        $link->setAttachment($att);
        $email->addEmailAttachment($link);
        $this->em->persist($link);
    }

    private function detectFileName(StorageMessage $part): ?string
    {
        foreach (['content-disposition', 'content-type'] as $h) {
            try {
                $v = $part->getHeader($h);
                $value = '';
                if ($v instanceof \Laminas\Mail\Header\HeaderInterface) {
                    $value = $v->getFieldValue();
                } elseif (is_string($v)) {
                    $value = $v;
                }
                if ('' !== $value && preg_match('/filename\s*=\s*"?([^";]+)"?/i', $value, $m)) {
                    return basename($this->toUtf8($this->decodeHeaderValue($m[1])));
                }
            } catch (\Throwable) {
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
     * Builds a safe dump file name from a UIDL/import key: only [A-Za-z0-9._-]
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

    private function dumpDir(string $connectorKey, \DateTimeInterface $date, string $status): string
    {
        return $this->mailDumpDir.'/'.$connectorKey.'/'.$date->format('Y/m/d').'/'.$status;
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
        } catch (\Throwable $e) {
            $this->log('Error pruning old mail dumps: '.$e->getMessage(), 'comment');
        }
    }

    /**
     * Content-ID of an inline image part (referenced by the HTML body via
     * "cid:<content id>"), without the angle brackets.
     */
    private function detectContentId(StorageMessage $part): ?string
    {
        try {
            $v = $part->getHeader('content-id');
            $value = '';
            if ($v instanceof \Laminas\Mail\Header\HeaderInterface) {
                $value = $v->getFieldValue();
            } elseif (is_string($v)) {
                $value = $v;
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

    private function getConnectorDumpKey(Connector $connector, string $pop3Username): string
    {
        $name = $connector->getDomain() ?: $pop3Username ?: (string) $connector->getId();

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

    private function removePendingDump(string $connectorKey, string $importKey, ?\DateTimeImmutable $date = null): void
    {
        try {
            $date ??= new \DateTimeImmutable();
            $dir = $this->dumpDir($connectorKey, $date, 'pending');
            $pendingFile = $dir.'/'.self::dumpFileName($importKey);
            if (file_exists($pendingFile)) {
                @unlink($pendingFile);
            }
        } catch (\Throwable) {
        }
    }

    private function dumpOriginalMessage(StorageMessage $msg, string $connectorKey, ?Email $email, ?string $importKey = null, string $status = 'success', ?string $errorReason = null): void
    {
        try {
            $date = $email?->getReceivedAt() ?? $this->extractDateFromMessage($msg) ?? new \DateTimeImmutable();
            $this->pruneOldDumps($connectorKey);
            $dir = $this->dumpDir($connectorKey, $date, $status);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if ($email?->getContentHash()) {
                $filename = self::dumpFileName($email->getContentHash());
            } elseif ($email?->getId()) {
                $filename = self::dumpFileName((string) $email->getId());
            } else {
                $filename = self::dumpFileName((string) $importKey);
            }
            $rawContent = $msg->getHeaders()->toString()."\n\n".$msg->getContent();
            if ('error' === $status && $errorReason) {
                $rawContent = "X-Mail-Fetch-Status: error\nX-Mail-Fetch-Error: ".str_replace(["\r", "\n"], ' ', $errorReason)."\nX-Mail-Fetch-Date: ".date('c')."\n\n".$rawContent;
            } else {
                $rawContent = "X-Mail-Fetch-Status: success\nX-Mail-Fetch-Date: ".date('c')."\n\n".$rawContent;
            }
            file_put_contents($dir.'/'.$filename, $rawContent);
            if ('success' === $status && $email?->getId() && $importKey) {
                $this->removePendingDump($connectorKey, $importKey, $date);
            }
        } catch (\Throwable $e) {
            $this->log('Error dumping the original message: '.$e->getMessage(), 'error');
        }
    }

    private function extractDateFromMessage(StorageMessage $msg): ?\DateTimeImmutable
    {
        $dateHeader = $this->safeHeader($msg, 'date');
        if ($dateHeader) {
            try {
                return new \DateTimeImmutable($dateHeader);
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function stringValue(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function intValue(array $config, string $key): int
    {
        $value = $config[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function boolValue(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;

        return is_bool($value) ? $value : $default;
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAlias;
use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailAddressLink;
use AvalynxOffice\Entity\EmailAttachmentLink;
use AvalynxOffice\Entity\EmailBodyLink;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Repository\AccountRepository;
use AvalynxOffice\Repository\FolderRepository;

class DataService
{
    public function __construct(
        private readonly AccountRepository $accountRepo,
        private readonly FolderRepository $folderRepo,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function getAllData(): array
    {
        $accounts = $this->accountRepo->findBy([], ['name' => 'ASC']);
        $mapEmail = fn (Email $e): array => [
            'id' => (string) $e->getId(),
            'subject' => $e->getSubject(),
            'previewBody' => $e->getPreviewBody(),
            'receivedAt' => $e->getReceivedAt()?->format(DATE_ATOM),
            'addresses' => array_map(fn (EmailAddressLink $link) => [
                'id' => (string) $link->getId(),
                'type' => $link->getType()->value,
                'address' => [
                    'id' => (string) $link->getAddress()?->getId(),
                    'email' => $link->getAddress()?->getEmail(),
                    'name' => $link->getAddress()?->getName(),
                ],
            ], $e->getEmailAddresses()->toArray()),
            'bodies' => array_map(fn (EmailBodyLink $link) => [
                'id' => (string) $link->getId(),
                'contentType' => $link->getContentType()->value,
                'body' => [
                    'id' => (string) $link->getBody()?->getId(),
                    'content' => $link->getBody()?->getContent(),
                ],
            ], $e->getEmailBodies()->toArray()),
            'attachments' => array_map(function (EmailAttachmentLink $link) {
                $a = $link->getAttachment();

                return [
                    'id' => $link->getId(),
                    'attachment' => $a ? [
                        'id' => (string) $a->getId(),
                        'name' => $a->getName(),
                        'size' => $a->getSize(),
                        'mimeType' => $a->getMimeType(),
                        'sha1' => $a->getSha1(),
                        'filePath' => $a->getFilePath(),
                    ] : null,
                ];
            }, $e->getEmailAttachments()->toArray()),
        ];

        return array_map(function (Account $mb) use ($mapEmail) {
            $folders = $this->folderRepo->findByAccountOrderByAssignment($mb);
            $folderData = array_map(function (Folder $f) use ($mapEmail) {
                $emails = [];
                foreach ($f->getEmailFolders() as $ef) {
                    $email = $ef->getEmail();
                    if ($email) {
                        $emails[] = $mapEmail($email);
                    }
                }

                return [
                    'id' => (string) $f->getId(),
                    'name' => $f->getName(),
                    'emails' => $emails,
                ];
            }, $folders);

            return [
                'id' => (string) $mb->getId(),
                'name' => $mb->getName(),
                'email' => $mb->getEmail(),
                'aliases' => array_map(static fn (AccountAlias $alias): array => [
                    'id' => (string) $alias->getId(),
                    'localPart' => $alias->getLocalPart(),
                    'address' => $alias->getAddress(),
                    'connector' => null !== $alias->getConnector() ? (string) $alias->getConnector()->getId() : null,
                ], $mb->getAliases()->toArray()),
                'addresses' => array_map(static fn (Address $address): array => [
                    'id' => (string) $address->getId(),
                    'email' => $address->getEmail(),
                    'name' => $address->getName(),
                ], $mb->getAddresses()->toArray()),
                'folders' => $folderData,
            ];
        }, $accounts);
    }
}

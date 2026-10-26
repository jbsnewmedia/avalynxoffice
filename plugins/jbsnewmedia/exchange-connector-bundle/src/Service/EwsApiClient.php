<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\ExchangeConnectorBundle\Service;

use garethp\ews\API;
use garethp\ews\API\Type;
use garethp\ews\API\Type\ArrayOfRealItemsType;
use garethp\ews\API\Type\BaseFolderIdType;
use garethp\ews\API\Type\FindItemParentType;
use garethp\ews\API\Type\ItemIdType;
use garethp\ews\API\Type\ItemType;
use garethp\ews\API\Type\MessageType;
use garethp\ews\API\Type\MimeContentType;
use garethp\ews\ContactsAPI;
use garethp\ews\RestrictionFormatter;

/**
 * Typed wrapper around the (loosely typed) EWS library. All interaction
 * with garethp/php-ews happens here: the fetchers work with clean value
 * objects only.
 */
final readonly class EwsApiClient
{
    private function __construct(private API $api)
    {
    }

    public static function create(string $host, string $username, string $password, string $version): self
    {
        $api = API::withUsernameAndPassword($host, $username, $password, ['version' => $version]);
        if (!$api instanceof API) {
            throw new \RuntimeException('Could not create the EWS client.');
        }

        return new self($api);
    }

    /**
     * Lists the item ids of a distinguished folder via FindItem (server
     * side sort, offset paging), honoring the limit and the "fetch from"
     * date (server side restriction).
     *
     * @return array{total: int, items: list<EwsItemRef>}
     */
    public function listItemRefs(string $folder, string $sortFieldUri, bool $oldestFirst, ?int $limit, ?\DateTimeInterface $fetchFrom = null): array
    {
        $refs = [];
        $total = 0;
        $offset = 0;
        $remaining = $limit;
        $client = $this->api->getClient();
        while (true) {
            $maxEntries = null !== $remaining ? min(50, $remaining) : 50;
            $request = [
                'Traversal' => 'Shallow',
                'ItemShape' => ['BaseShape' => 'IdOnly'],
                'IndexedPageItemView' => [
                    'MaxEntriesReturned' => $maxEntries,
                    'Offset' => $offset,
                    'BasePoint' => 'Beginning',
                ],
                'SortOrder' => [[
                    'Order' => $oldestFirst ? 'Ascending' : 'Descending',
                    'FieldURI' => ['FieldURI' => $sortFieldUri],
                ]],
                'ParentFolderIds' => $this->folderId($folder)->toArray(true),
            ];
            $restriction = self::fetchFromRestriction($folder, $fetchFrom);
            if ([] !== $restriction) {
                $request['Restriction'] = $restriction;
            }
            $result = $client->FindItem(Type::buildFromArray($request));
            if (!$result instanceof FindItemParentType) {
                throw new \RuntimeException('Unexpected FindItem response.');
            }
            $totalInView = $result->getTotalItemsInView();
            $total = max($total, is_numeric($totalInView) ? (int) $totalInView : 0);
            [$pageRefs, $pageCount] = $this->extractItemRefs($result->getItems());
            $refs = array_merge($refs, $pageRefs);
            if (null !== $remaining) {
                $remaining -= $pageCount;
                if ($remaining <= 0) {
                    break;
                }
            }
            if ($result->isIncludesLastItemInRange() || 0 === $pageCount) {
                break;
            }
            $nextOffset = $result->getIndexedPagingOffset();
            $offset = is_numeric($nextOffset) ? (int) $nextOffset : $offset + $pageCount;
        }

        return ['total' => $total, 'items' => $refs];
    }

    /**
     * Lists full contact items of the default contacts folder via FindItem
     * (AllProperties, offset paging), honoring the limit.
     *
     * @return array{total: int, items: list<object>}
     */
    public function findContacts(?int $limit): array
    {
        $items = [];
        $total = 0;
        $offset = 0;
        $remaining = $limit;
        $client = $this->api->getClient();
        while (true) {
            $maxEntries = null !== $remaining ? min(100, $remaining) : 100;
            $request = Type::buildFromArray([
                'Traversal' => 'Shallow',
                'ItemShape' => ['BaseShape' => 'AllProperties'],
                'IndexedPageItemView' => [
                    'MaxEntriesReturned' => $maxEntries,
                    'Offset' => $offset,
                    'BasePoint' => 'Beginning',
                ],
                'SortOrder' => [[
                    'Order' => 'Ascending',
                    'FieldURI' => ['FieldURI' => 'contacts:DisplayName'],
                ]],
                'ParentFolderIds' => $this->folderId('contacts')->toArray(true),
            ]);
            $result = $client->FindItem($request);
            if (!$result instanceof FindItemParentType) {
                throw new \RuntimeException('Unexpected FindItem response.');
            }
            $totalInView = $result->getTotalItemsInView();
            $total = max($total, is_numeric($totalInView) ? (int) $totalInView : 0);
            [$pageItems, $pageCount] = $this->extractObjects($result->getItems());
            $items = array_merge($items, $pageItems);
            if (null !== $remaining) {
                $remaining -= $pageCount;
                if ($remaining <= 0) {
                    break;
                }
            }
            if ($result->isIncludesLastItemInRange() || 0 === $pageCount) {
                break;
            }
            $nextOffset = $result->getIndexedPagingOffset();
            $offset = is_numeric($nextOffset) ? (int) $nextOffset : $offset + $pageCount;
        }

        return ['total' => $total, 'items' => $items];
    }

    /**
     * Loads one message as raw RFC822 (MimeContent) or null.
     */
    public function getItemMime(EwsItemRef $ref): ?string
    {
        $full = $this->api->getItem($ref->toArray(), ['ItemShape' => ['BaseShape' => 'IdOnly', 'IncludeMimeContent' => true]]);
        if (!$full instanceof MessageType) {
            return null;
        }
        $mimeContent = $full->getMimeContent();
        if (!$mimeContent instanceof MimeContentType) {
            return null;
        }

        return EwsTypeHelper::mimeContentRaw($mimeContent);
    }

    /**
     * Loads one item with all properties (used for calendar entries).
     */
    public function getItem(EwsItemRef $ref): ?object
    {
        $item = $this->api->getItem($ref->toArray(), ['ItemShape' => ['BaseShape' => 'AllProperties']]);
        if (!$item instanceof ItemType) {
            return null;
        }

        return $item;
    }

    /**
     * Moves one item to the "Deleted Items" folder of the Exchange server.
     */
    public function deleteItem(EwsItemRef $ref): void
    {
        $this->api->deleteItems([$ref->toArray()], ['DeleteType' => 'MoveToDeletedItems', 'SendNotification' => false]);
    }

    /**
     * Server side "fetch from" restriction for the FindItem request: mails
     * are filtered by the received date, calendar entries by their start
     * date. Empty array = no restriction.
     *
     * @return array<string, mixed>
     */
    private static function fetchFromRestriction(string $folder, ?\DateTimeInterface $fetchFrom): array
    {
        if (null === $fetchFrom) {
            return [];
        }
        $value = $fetchFrom->format('Y-m-d\T00:00:00\Z');
        $formatted = 'inbox' === $folder
            ? RestrictionFormatter::format('item', [
                'IsGreaterThanOrEqualTo' => ['DateTimeReceived' => $value],
            ])
            : RestrictionFormatter::format('calendar', [
                'IsGreaterThanOrEqualTo' => ['Start' => $value],
            ]);
        \assert(is_array($formatted));

        /* @var array<string, mixed> $formatted */
        return $formatted;
    }

    /**
     * @return array{0: list<EwsItemRef>, 1: int}
     */
    private function extractItemRefs(mixed $items): array
    {
        $refs = [];
        foreach ($this->iterableOf($items) as $item) {
            if (!$item instanceof MessageType) {
                continue;
            }
            $itemId = $item->getItemId();
            if (!$itemId instanceof ItemIdType) {
                continue;
            }
            $id = $itemId->getId();
            if (!is_string($id) || '' === $id) {
                continue;
            }
            $changeKey = $itemId->getChangeKey();
            if (!is_string($changeKey)) {
                $changeKey = null;
            }
            $refs[] = new EwsItemRef($id, $changeKey);
        }
        $count = count($refs);

        return [$refs, $count];
    }

    /**
     * @return array{0: list<object>, 1: int}
     */
    private function extractObjects(mixed $items): array
    {
        $objects = [];
        foreach ($this->iterableOf($items) as $item) {
            if (is_object($item)) {
                $objects[] = $item;
            }
        }
        $count = count($objects);

        return [$objects, $count];
    }

    /**
     * @return iterable<int, object>
     */
    private function iterableOf(mixed $items): iterable
    {
        if (!$items instanceof ArrayOfRealItemsType) {
            return [];
        }

        return $items;
    }

    private function folderId(string $folder): BaseFolderIdType
    {
        $folderId = match ($folder) {
            'inbox' => $this->api->getMailbox()->getFolderId(),
            'calendar' => $this->api->getCalendar()->getFolderId(),
            'contacts' => $this->contactsApi()->getFolderId(),
            default => throw new \RuntimeException(sprintf('Unknown folder "%s".', $folder)),
        };
        if (!$folderId instanceof BaseFolderIdType) {
            throw new \RuntimeException(sprintf('Could not resolve the folder "%s".', $folder));
        }

        return $folderId;
    }

    private function contactsApi(): ContactsAPI
    {
        $api = new ContactsAPI();
        $api->setClient($this->api->getClient());

        return $api;
    }
}

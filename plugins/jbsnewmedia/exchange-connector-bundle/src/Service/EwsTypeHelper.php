<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\ExchangeConnectorBundle\Service;

use garethp\ews\API\Type\MimeContentType;

/**
 * Small helpers for reading values out of the dynamically typed EWS
 * response objects. PHP's SoapClient maps the text content of a
 * simpleContent element (MimeContent) to the "_" property and already
 * decodes its base64 payload.
 */
final class EwsTypeHelper
{
    /**
     * Returns the raw RFC822 message of a MimeContent element (already
     * base64-decoded by the SoapClient) or null.
     */
    public static function mimeContentRaw(?MimeContentType $mimeContent): ?string
    {
        if (!$mimeContent instanceof MimeContentType) {
            return null;
        }
        // PHP's SoapClient maps the text content of the simpleContent
        // element to the "_" property and decodes its base64 payload.
        $vars = get_object_vars($mimeContent);
        $raw = $vars['_'] ?? null;
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        return $raw;
    }

    /**
     * The text content of an EWS dictionary entry (email addresses, phone
     * numbers) is mapped to the "_" property as well.
     */
    public static function entryValue(object $entry): ?string
    {
        $vars = (array) $entry;
        $value = $vars['_'] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' !== $value ? $value : null;
    }

    /**
     * Parses an EWS datetime (e.g. "2026-10-26T09:00:00Z" or with offset).
     */
    public static function dateTime(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

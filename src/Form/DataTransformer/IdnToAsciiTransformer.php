<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form\DataTransformer;

use AvalynxOffice\Service\Idn\IdnService;
use Symfony\Component\Form\DataTransformerInterface;

/**
 * Form transformer for fields that hold a domain or an email address:
 * everything the user types is normalized to punycode before it reaches the
 * model (so lookups, mail routing and filesystem paths stay ASCII-safe and
 * the ASCII-only validation patterns keep working), and the stored value is
 * decoded to Unicode for the form view ("in den Anzeigen dekodiert").
 *
 * Normalization is idempotent: already punycode values round-trip unchanged.
 *
 * @implements DataTransformerInterface<string, string>
 */
final readonly class IdnToAsciiTransformer implements DataTransformerInterface
{
    public function __construct(
        private IdnService $idnService,
    ) {
    }

    #[\Override]
    public function transform(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return $this->idnService->toUnicode($value);
    }

    #[\Override]
    public function reverseTransform(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return $this->idnService->toAscii(trim($value));
    }
}

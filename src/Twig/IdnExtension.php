<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Twig;

use AvalynxOffice\Service\Idn\IdnService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Display filter for IDN values: stored emails and domains are punycode
 * ("admin@xn--bstoff-bua.de") and are decoded to their Unicode form
 * ("admin@bästoff.de") for rendering. Values without a punycode domain pass
 * through unchanged, so the filter can be applied to any address.
 */
class IdnExtension extends AbstractExtension
{
    public function __construct(
        private readonly IdnService $idnService,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('idn_to_utf8', $this->toUnicode(...)),
        ];
    }

    public function toUnicode(?string $value): string
    {
        return null === $value ? '' : $this->idnService->toUnicode($value);
    }
}

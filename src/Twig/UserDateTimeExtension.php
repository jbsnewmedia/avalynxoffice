<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Twig;

use AvalynxOffice\Service\UserDateTimeFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig access to the central date/time rendering: converts to the time zone
 * of the logged-in user and formats it with a configurable variant. Use it
 * in templates via {{ ...|user_datetime('variant') }} — without an explicit
 * variant the format preference of the user (profile setting) is used. The
 * logic lives in UserDateTimeFormatter so controllers can render dates
 * exactly the same way.
 */
class UserDateTimeExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserDateTimeFormatter $formatter,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('user_datetime', $this->userDatetime(...)),
        ];
    }

    /**
     * Renders a date/time in the time zone and format variant of the logged
     * in user. Null input renders as an empty string, plain strings are
     * parsed as UTC (the storage time zone).
     *
     * @param \DateTimeInterface|string|null $dateTime
     */
    public function userDatetime(mixed $dateTime, ?string $variant = null): string
    {
        return $this->formatter->format($dateTime, $variant);
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Twig;

use AvalynxOffice\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Central wrapper for rendering dates and times: converts to the time zone
 * of the logged-in user and formats it with a configurable variant.
 *
 * The variants below are the single place where date/time formats are
 * defined; add new variants here and reference them in the templates via
 * {{ ...|user_datetime('variant') }}. Without an explicit variant the
 * format preference of the user (profile setting) is used.
 */
class UserDateTimeExtension extends AbstractExtension
{
    /**
     * Format variants: variant => [format, clock suffix flag]. The suffix is
     * appended in the translated form ("Uhr"/"o'clock") when enabled.
     *
     * @var array<string, array{format: string, suffix: bool}>
     */
    private const array VARIANTS = [
        'short' => ['format' => 'd.m.Y H:i', 'suffix' => false],
        'medium' => ['format' => 'd.m.Y, H:i:s', 'suffix' => false],
        'long' => ['format' => 'd.m.Y, H:i:s', 'suffix' => true],
        'date' => ['format' => 'd.m.Y', 'suffix' => false],
        'time' => ['format' => 'H:i', 'suffix' => false],
        'iso' => ['format' => 'Y-m-d H:i:s', 'suffix' => false],
    ];

    public const string DEFAULT_VARIANT = 'short';

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly TranslatorInterface $translator,
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
        if (null === $dateTime || '' === $dateTime) {
            return '';
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        $timezoneName = $user instanceof User && null !== $user->getTimezone()
            ? $user->getTimezone()
            : 'UTC';
        $formatVariant = $variant ?? ($user instanceof User && null !== $user->getTimeFormat()
            ? $user->getTimeFormat()
            : self::DEFAULT_VARIANT);

        $configuration = self::VARIANTS[$formatVariant] ?? self::VARIANTS[self::DEFAULT_VARIANT];

        if (is_string($dateTime)) {
            $dateTime = new \DateTimeImmutable($dateTime, new \DateTimeZone('UTC'));
        }

        if (!$dateTime instanceof \DateTimeInterface) {
            return '';
        }

        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Exception) {
            // An invalid stored time zone falls back to the system default.
            $timezone = new \DateTimeZone('UTC');
        }
        $converted = \DateTimeImmutable::createFromInterface($dateTime)->setTimezone($timezone);
        $rendered = $converted->format($configuration['format']);

        if ($configuration['suffix']) {
            $rendered .= ' '.$this->translator->trans('common.oclock');
        }

        return $rendered;
    }
}

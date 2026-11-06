<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Util;

/**
 * Locale-aware string comparison for user-visible sorting ("a-z in the
 * current language"): uses the ICU collator when available and falls back to
 * a case-insensitive natural comparison otherwise.
 */
final class LocaleSort
{
    /**
     * The optional collator factory seam lets tests simulate deployments
     * without ext-intl (where no collator can be created).
     *
     * @param (\Closure(?string): (?\Collator))|null $collatorFactory
     */
    public static function compare(string $a, string $b, ?string $locale = null, ?\Closure $collatorFactory = null): int
    {
        $factory = $collatorFactory ?? (static fn (?string $locale): ?\Collator => class_exists(\Collator::class) ? \Collator::create($locale ?? \Locale::getDefault()) : null);

        $collator = $factory($locale);
        if (null === $collator) {
            return strnatcasecmp($a, $b);
        }

        // Case-insensitive, accent-sensitive ordering ("a-z in the current
        // language"): 'administrator' equals 'Administrator', 'ä' sorts
        // into the alphabet.
        $collator->setStrength(\Collator::SECONDARY);
        $result = $collator->compare($a, $b);

        return false === $result ? strnatcasecmp($a, $b) : $result;
    }
}

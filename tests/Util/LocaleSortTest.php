<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Util;

use AvalynxOffice\Util\LocaleSort;
use PHPUnit\Framework\TestCase;

/**
 * Locale-aware comparison for user-visible sorting: umlauts sort into the
 * alphabet (de) and the comparison is case-insensitive.
 */
final class LocaleSortTest extends TestCase
{
    public function testCompareSortsUmlautsIntoTheAlphabetInGerman(): void
    {
        self::assertLessThan(0, LocaleSort::compare('Benutzer', 'Öffentlich', 'de'));
        self::assertLessThan(0, LocaleSort::compare('Arbeit', 'Benutzer', 'de'));
        self::assertGreaterThan(0, LocaleSort::compare('Öffentlich', 'Arbeit', 'de'));
    }

    public function testCompareIsCaseInsensitive(): void
    {
        self::assertSame(0, LocaleSort::compare('administrator', 'Administrator', 'de'));
    }

    public function testCompareHandlesIdenticalStrings(): void
    {
        self::assertSame(0, LocaleSort::compare('Benutzer', 'Benutzer', 'de'));
    }

    public function testCompareSortsSortedArrayAlphabetically(): void
    {
        $labels = ['Benutzer', 'Administrator', 'Änderung'];
        usort($labels, static fn (string $a, string $b): int => LocaleSort::compare($a, $b, 'de'));

        self::assertSame(['Administrator', 'Änderung', 'Benutzer'], $labels);
    }

    public function testCompareFallsBackToNaturalCaseInsensitiveOrderWithoutACollator(): void
    {
        // Deployments without ext-intl cannot create a collator; the fallback
        // keeps a case-insensitive natural order.
        self::assertSame(1, LocaleSort::compare('benutzer10', 'Benutzer2', 'de', static fn (): ?\Collator => null));
        self::assertSame(-1, LocaleSort::compare('Benutzer2', 'benutzer10', 'de', static fn (): ?\Collator => null));
    }
}

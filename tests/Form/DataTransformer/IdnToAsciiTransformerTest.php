<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Form\DataTransformer;

use AvalynxOffice\Form\DataTransformer\IdnToAsciiTransformer;
use AvalynxOffice\Service\Idn\IdnService;
use PHPUnit\Framework\TestCase;

/**
 * The form transformer: submitted IDN input is stored as punycode, the
 * stored value is displayed decoded.
 */
final class IdnToAsciiTransformerTest extends TestCase
{
    private IdnToAsciiTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new IdnToAsciiTransformer(new IdnService());
    }

    public function testReverseTransformStoresUnicodeEmailAsPunycode(): void
    {
        self::assertSame('jbo@xn--bstoff-bua.de', $this->transformer->reverseTransform('jbo@bästoff.de'));
    }

    public function testReverseTransformStoresUnicodeDomainAsPunycode(): void
    {
        self::assertSame('xn--bstoff-bua.de', $this->transformer->reverseTransform('bästoff.de'));
    }

    public function testReverseTransformTrimsWhitespace(): void
    {
        self::assertSame('user@example.com', $this->transformer->reverseTransform('  user@example.com '));
    }

    public function testReverseTransformIsIdempotentForPunycode(): void
    {
        self::assertSame('user@xn--bstoff-bua.de', $this->transformer->reverseTransform('user@xn--bstoff-bua.de'));
    }

    public function testReverseTransformPassesInvalidInputForValidation(): void
    {
        self::assertSame('kein@domain!', $this->transformer->reverseTransform('kein@domain!'));
    }

    public function testReverseTransformRejectsNonString(): void
    {
        self::assertSame('', $this->transformer->reverseTransform(null));
    }

    public function testTransformDisplaysPunycodeEmailDecoded(): void
    {
        self::assertSame('admin@bästoff.de', $this->transformer->transform('admin@xn--bstoff-bua.de'));
    }

    public function testTransformPassesAsciiThrough(): void
    {
        self::assertSame('admin@example.com', $this->transformer->transform('admin@example.com'));
    }

    public function testTransformRejectsNonString(): void
    {
        self::assertSame('', $this->transformer->transform(null));
    }
}

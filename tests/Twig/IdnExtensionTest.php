<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Twig;

use AvalynxOffice\Service\Idn\IdnService;
use AvalynxOffice\Twig\IdnExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The idn_to_utf8 display filter: punycode emails and domains render in
 * their Unicode form, everything else passes through.
 */
final class IdnExtensionTest extends TestCase
{
    public function testFilterIsRegistered(): void
    {
        $extension = new IdnExtension(new IdnService());

        self::assertCount(1, $extension->getFilters());
    }

    public function testFilterDecodesPunycodeEmailInTemplate(): void
    {
        $twig = $this->createTwig();

        self::assertSame(
            'admin@bästoff.de',
            $twig->render('template', ['email' => 'admin@xn--bstoff-bua.de']),
        );
    }

    public function testFilterPassesAsciiEmailThrough(): void
    {
        $twig = $this->createTwig();

        self::assertSame(
            'admin@example.com',
            $twig->render('template', ['email' => 'admin@example.com']),
        );
    }

    public function testFilterHandlesNull(): void
    {
        $twig = $this->createTwig();

        self::assertSame('', $twig->render('template', ['email' => null]));
    }

    private function createTwig(): Environment
    {
        $twig = new Environment(new ArrayLoader([
            'template' => '{{ email|idn_to_utf8 }}',
        ]));
        $twig->addExtension(new IdnExtension(new IdnService()));

        return $twig;
    }
}

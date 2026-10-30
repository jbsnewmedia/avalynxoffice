<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Twig;

use AvalynxOffice\Twig\EmailStripActiveTagsExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFilter;

final class EmailStripActiveTagsExtensionTest extends TestCase
{
    private EmailStripActiveTagsExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new EmailStripActiveTagsExtension();
    }

    public function testRegistersFilter(): void
    {
        $filters = $this->extension->getFilters();
        self::assertCount(1, $filters);
        self::assertInstanceOf(TwigFilter::class, $filters[0]);
        self::assertSame('email_strip_active_tags', $filters[0]->getName());
    }

    public function testEmptyAndNullInputYieldEmptyString(): void
    {
        self::assertSame('', $this->extension->stripActiveTags(''));
        self::assertSame('', $this->extension->stripActiveTags(null));
    }

    public function testHarmlessHtmlStaysUntouched(): void
    {
        $html = '<div style="color:red"><p>Hallo <b>Welt</b> <a href="https://example.com">Link</a> <img src="cid:logo" alt="logo"></p><table><tr><td>Zelle</td></tr></table></div>';

        self::assertSame($html, $this->extension->stripActiveTags($html));
    }

    public function testRemovesMetaRefreshBaseAndLinkTags(): void
    {
        $html = '<META HTTP-EQUIV="refresh" content="0;url=https://evil.example.com">'
            .'<base href="https://evil.example.com/" />'
            ."<link\n rel=\"stylesheet\" href='https://evil.example.com/x.css'>"
            .'<meta charset="utf-8"/>'
            .'<p>Inhalt</p>';

        self::assertSame('<p>Inhalt</p>', $this->extension->stripActiveTags($html));
    }

    public function testRemovesScriptObjectIframeFormAppletWithContent(): void
    {
        $html = '<p>A</p>'
            .'<script type="text/javascript">alert("x")</script>'
            .'<SCRIPT>document.location="https://evil.example.com"</SCRIPT >'
            .'<object data="x.swf"><param name="a" value="b"></object>'
            .'<iframe src="https://evil.example.com"><p>fallback</p></iframe>'
            .'<frame src="x.html">frame content</frame>'
            .'<form action="https://evil.example.com/phish" method="post"><input name="pw"><button>Go</button></form>'
            .'<applet code="Evil.class">applet content</applet>'
            .'<p>B</p>';

        self::assertSame('<p>A</p><p>B</p>', $this->extension->stripActiveTags($html));
    }

    public function testRemovesEmbedAndUnclosedContainerTags(): void
    {
        $html = '<p>A</p><embed src="x.swf" type="application/x-shockwave-flash"><p>B</p><script src="https://evil.example.com/x.js">rest';

        $result = $this->extension->stripActiveTags($html);

        self::assertStringContainsString('<p>A</p>', $result);
        self::assertStringContainsString('<p>B</p>', $result);
        self::assertStringNotContainsString('<embed', $result);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('evil.example.com', $result);
    }

    public function testIsCaseInsensitiveAndTolerantToWhitespace(): void
    {
        $html = '<p>A</p>< ScRiPt >alert(1)</ScRiPt><  meta   http-equiv = "refresh"   content="0" ><p>B</p>';

        self::assertSame('<p>A</p><p>B</p>', $this->extension->stripActiveTags($html));
    }

    public function testHandlesGreaterThanInsideAttributeValue(): void
    {
        $html = '<p>A</p><meta http-equiv="refresh" content="0;url=https://evil.example.com/?a=b>c"><p>B</p>';

        self::assertSame('<p>A</p><p>B</p>', $this->extension->stripActiveTags($html));
    }

    public function testNestedTagsDoNotReassembleIntoActiveTags(): void
    {
        $html = '<p>A</p><scr<meta>ipt>alert(1)</script><p>B</p>';

        $result = $this->extension->stripActiveTags($html);

        self::assertStringNotContainsStringIgnoringCase('<script', $result);
        self::assertStringNotContainsString('<meta', $result);
        self::assertStringContainsString('<p>A</p>', $result);
        self::assertStringContainsString('<p>B</p>', $result);
    }

    public function testDoesNotTouchElementsWithSimilarNames(): void
    {
        $html = '<p>A</p><meter value="2" min="0" max="10">2</meter><formfield>x</formfield><linkage>y</linkage><p>B</p>';

        self::assertSame($html, $this->extension->stripActiveTags($html));
    }
}

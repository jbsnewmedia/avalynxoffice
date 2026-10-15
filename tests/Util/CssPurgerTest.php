<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\CssPurger;
use PHPUnit\Framework\TestCase;

class CssPurgerTest extends TestCase
{
    private CssPurger $purger;

    protected function setUp(): void
    {
        $this->purger = new CssPurger();
    }

    public function testPurgeWithReport(): void
    {
        $css = '
            .keep { color: red; }
            .remove { color: blue; }
            .keep, .remove { font-size: 10px; }
            @media (min-width: 100px) {
                .keep-inner { color: green; }
                .remove-inner { color: yellow; }
            }
        ';
        $allowed = ['keep', 'keep-inner'];
        $report = $this->purger->purgeWithReport($css, $allowed);

        $this->assertStringContainsString('.keep', $report['css']);
        $this->assertStringNotContainsString('.remove', $report['css']);
        $this->assertStringContainsString('.keep-inner', $report['css']);
        $this->assertStringNotContainsString('.remove-inner', $report['css']);

        $this->assertEquals(5, $report['stats']['rules_total']);
        $this->assertEquals(3, $report['stats']['rules_kept']);
        $this->assertEquals(2, $report['stats']['rules_removed']);
    }

    public function testSafelist(): void
    {
        $css = '.safe { color: red; }';
        $report = $this->purger->purgeWithReport($css, [], ['safe']);
        $this->assertStringContainsString('.safe', $report['css']);
    }

    public function testPurgeHandlesCommentsDanglingContentQuotedStringsAndEmptySelectors(): void
    {
        $css = <<<'CSS'
/* preserved comment */
, .keep::before { content: "a\"b"; }
trailing text without brace
CSS;

        $report = $this->purger->purgeWithReport($css, ['keep']);

        $this->assertStringContainsString('/* preserved comment */', $report['css']);
        $this->assertStringContainsString('.keep::before', $report['css']);
        $this->assertStringContainsString('trailing text without brace', $report['css']);
        $this->assertSame(1, $report['stats']['rules_total']);
        $this->assertSame(1, $report['stats']['rules_kept']);
    }

    public function testPurgeLeavesUnterminatedCommentsUntouched(): void
    {
        $css = '/* unterminated comment';

        $report = $this->purger->purgeWithReport($css, ['keep']);

        $this->assertSame($css, $report['css']);
        $this->assertSame(0, $report['stats']['rules_total']);
    }

    public function testPurgeRemovesSelectorsWithoutCssClasses(): void
    {
        $report = $this->purger->purgeWithReport('body { color: red; }', ['keep']);

        $this->assertSame('', trim($report['css']));
        $this->assertSame(1, $report['stats']['rules_removed']);
    }

    public function testPurgeHandlesUnterminatedBlocksAndEmptySelectors(): void
    {
        $report = $this->purger->purgeWithReport("{}\n.keep { color: red; ", ['keep']);

        $this->assertStringNotContainsString('{}', $report['css']);
        $this->assertStringContainsString('.keep{ color: red;}', $report['css']);
        $this->assertSame(1, $report['stats']['rules_total']);
        $this->assertSame(1, $report['stats']['rules_kept']);
    }

    public function testPurgeHandlesEscapedTrailingCharactersInQuotedContent(): void
    {
        $report = $this->purger->purgeWithReport('.keep{content:"\\', ['keep']);

        $this->assertStringContainsString('.keep{content:"}', $report['css']);
        $this->assertSame(1, $report['stats']['rules_total']);
        $this->assertSame(1, $report['stats']['rules_kept']);
    }
}

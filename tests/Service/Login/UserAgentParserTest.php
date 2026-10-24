<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Login;

use AvalynxOffice\Service\Login\UserAgentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserAgentParserTest extends TestCase
{
    #[DataProvider('userAgentProvider')]
    public function testParsesBrowserAndOs(string $userAgent, string $expectedBrowser, string $expectedOs): void
    {
        $parsed = UserAgentParser::parse($userAgent);

        self::assertSame(['browser' => $expectedBrowser, 'os' => $expectedOs], $parsed);
    }

    public static function userAgentProvider(): iterable
    {
        yield 'chrome on windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'Chrome',
            'Windows',
        ];

        yield 'edge on windows (contains chrome)' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 Edg/121.0.0.0',
            'Microsoft Edge',
            'Windows',
        ];

        yield 'firefox on linux' => [
            'Mozilla/5.0 (X11; Linux x86_64; rv:122.0) Gecko/20100101 Firefox/122.0',
            'Firefox',
            'Linux',
        ];

        yield 'safari on macos' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
            'Safari',
            'macOS',
        ];

        yield 'safari on iphone' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
            'Safari',
            'iOS',
        ];

        yield 'chrome on android' => [
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Mobile Safari/537.36',
            'Chrome',
            'Android',
        ];

        yield 'opera (contains chrome)' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0',
            'Opera',
            'Windows',
        ];

        yield 'internet explorer legacy' => [
            'Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko',
            'Internet Explorer',
            'Windows',
        ];

        yield 'chromebook' => [
            'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Chrome',
            'ChromeOS',
        ];

        yield 'empty string' => ['', 'Unbekannt', 'Unbekannt'];

        yield 'unknown agent' => ['SomeRandomBot/1.0', 'Unbekannt', 'Unbekannt'];
    }
}

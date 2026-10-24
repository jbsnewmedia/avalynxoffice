<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Login;

/**
 * Extracts a readable device description (browser and operating system)
 * from a raw user agent string — without external dependencies.
 */
final class UserAgentParser
{
    /**
     * @return array{browser: string, os: string}
     */
    public static function parse(?string $userAgent): array
    {
        $userAgent = trim((string) $userAgent);

        return [
            'browser' => self::browser($userAgent),
            'os' => self::os($userAgent),
        ];
    }

    private static function browser(string $userAgent): string
    {
        if ('' === $userAgent) {
            return 'Unbekannt';
        }

        // Edge must be matched before Chrome (its UA contains both).
        if (str_contains($userAgent, 'Edg/') || str_contains($userAgent, 'Edge/')) {
            return 'Microsoft Edge';
        }

        if (str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera')) {
            return 'Opera';
        }

        if (str_contains($userAgent, 'Firefox/')) {
            return 'Firefox';
        }

        if (str_contains($userAgent, 'Chrome/')) {
            return 'Chrome';
        }

        if (str_contains($userAgent, 'Safari/')) {
            return 'Safari';
        }

        if (str_contains($userAgent, 'MSIE') || str_contains($userAgent, 'Trident/')) {
            return 'Internet Explorer';
        }

        return 'Unbekannt';
    }

    private static function os(string $userAgent): string
    {
        if ('' === $userAgent) {
            return 'Unbekannt';
        }

        return match (true) {
            str_contains($userAgent, 'Windows Phone') => 'Windows Phone',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') || str_contains($userAgent, 'iPod') => 'iOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unbekannt',
        };
    }
}

<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin\Pop3ConnectorBundle\Service;

/**
 * Global state toggles for the shadowed mb_/iconv_ functions below. The class
 * lives in the autoloadable test namespace so every para-test worker can load
 * the shadows via the autoloader, independent of which test files a worker
 * receives.
 */
final class Pop3FetcherTestFunctionOverrides
{
    public static bool $forceMissingIconvMimeDecode = false;

    public static bool $forceMbDetectEncodingFailure = false;

    public static bool $forceInvalidMbConvertEncoding = false;
}

namespace JBSNewMedia\Pop3ConnectorBundle\Service;

use AvalynxOffice\Tests\Plugin\Pop3ConnectorBundle\Service\Pop3FetcherTestFunctionOverrides;

/**
 * Namespace-level shadows for the functions Pop3Fetcher calls unqualified.
 * PHP resolves unqualified calls in this namespace to these functions at
 * runtime and falls back to the global ones when the force-flags are off.
 */
function function_exists(string $function): bool
{
    if ('iconv_mime_decode' === $function && Pop3FetcherTestFunctionOverrides::$forceMissingIconvMimeDecode) {
        return false;
    }

    return \function_exists($function);
}

function mb_convert_encoding(array|string|int|float|null $string, ?string $toEncoding, array|string|null $fromEncoding = null): array|string|false
{
    if (Pop3FetcherTestFunctionOverrides::$forceInvalidMbConvertEncoding) {
        return "\xfe\xfe";
    }

    return \mb_convert_encoding($string, $toEncoding, $fromEncoding);
}

/**
 * @param array<int, string>|string|null $encodings
 */
function mb_detect_encoding(string $string, array|string|null $encodings = null, bool $strict = false): string|false
{
    if (Pop3FetcherTestFunctionOverrides::$forceMbDetectEncodingFailure) {
        return false;
    }

    return \mb_detect_encoding($string, $encodings, $strict);
}

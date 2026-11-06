<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Idn;

/**
 * Central IDN handling for AvalynxOffice.
 *
 * The application stores domains — and the domain part of email addresses —
 * exclusively in punycode form: it is the only form that is stable for
 * database lookups, mail routing and filesystem paths, and it matches what
 * mail standards and DNS expect. Everything that a user types (login email,
 * admin user email, connector domain) is normalized through {@see toAscii}
 * before it is stored; everything that is rendered for a human is decoded
 * through {@see toUnicode}.
 *
 * Both directions keep the input unchanged when it cannot be converted:
 * pure ASCII passes through idempotently, and invalid input stays invalid so
 * that validators can render their own messages instead of this service
 * silently mangling the value.
 */
final readonly class IdnService
{
    /**
     * Normalizes a domain, or the domain part of an email address, to its
     * punycode form ("bästoff.de" and "bästoff.de" both become
     * "xn--bstoff-bua.de"). The local part of an email address is kept as is.
     */
    public function toAscii(string $value): string
    {
        return $this->mapDomain($value, static function (string $domain): ?string {
            $converted = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            return is_string($converted) ? $converted : null;
        });
    }

    /**
     * Decodes a domain, or the domain part of an email address, from its
     * punycode form ("admin@xn--bstoff-bua.de" becomes "admin@bästoff.de")
     * for display purposes.
     */
    public function toUnicode(string $value): string
    {
        return $this->mapDomain($value, static function (string $domain): ?string {
            $converted = idn_to_utf8($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            return is_string($converted) ? $converted : null;
        });
    }

    /**
     * Applies the given conversion to the domain part of the value. A value
     * containing "@" is treated as local@domain, everything else as a bare
     * domain (optionally with path-less subdomains). Values without a
     * convertible domain part are returned unchanged.
     *
     * @param \Closure(string): (string|null) $convert
     */
    private function mapDomain(string $value, \Closure $convert): string
    {
        $separator = strrpos($value, '@');
        if (false === $separator) {
            $local = '';
            $domain = $value;
        } else {
            $local = substr($value, 0, $separator + 1);
            $domain = substr($value, $separator + 1);
        }

        if ('' === $domain) {
            return $value;
        }

        $converted = $convert(trim(strtolower($domain)));
        if (null === $converted || '' === $converted) {
            return $value;
        }

        return $local.$converted;
    }
}

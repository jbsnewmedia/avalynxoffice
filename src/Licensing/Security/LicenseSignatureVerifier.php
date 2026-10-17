<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Security;

/**
 * Verifies Ed25519 detached signatures over the originally signed bytes.
 */
final class LicenseSignatureVerifier
{
    public function verify(string $signedContent, string $signature, string $publicKey): bool
    {
        if ('' === $signature || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen($publicKey)) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $signedContent, $publicKey);
    }
}

<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Licensing\Security;

use AvalynxOffice\Licensing\Model\LicenseKeyStatus;
use AvalynxOffice\Licensing\Validation\LicenseKeyException;

/**
 * Encodes and decodes license keys of the format.
 *
 *     WOL1.PAYLOAD.SIGNATURE
 *
 * where PAYLOAD and SIGNATURE are Base64URL strings. The signature is
 * created over the exact bytes of the string "WOL1.PAYLOAD".
 */
final class LicenseCodec
{
    public const string KEY_PREFIX = 'WOL1';
    public const int MAX_KEY_LENGTH = 8192;
    public const int MAX_PAYLOAD_LENGTH = 4096;
    private const int JSON_DEPTH = 6;

    /**
     * Creates a signed license key from a payload and an Ed25519 secret key.
     *
     * This method exists for tests and the separate issuance tool only.
     * The production application never signs license keys.
     *
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload, string $ed25519SecretKey): string
    {
        if ('' === $ed25519SecretKey) {
            throw new \InvalidArgumentException('The Ed25519 secret key must not be empty.');
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $encodedPayload = $this->encodeBase64Url($json);
        $signedContent = self::KEY_PREFIX.'.'.$encodedPayload;
        $signature = sodium_crypto_sign_detached($signedContent, $ed25519SecretKey);

        return $signedContent.'.'.$this->encodeBase64Url($signature);
    }

    /**
     * Splits a license key into its signed content, signature and payload
     * without re-serializing the JSON data.
     *
     * @return array{signed_content: string, signature: string, payload_raw: string}
     *
     * @throws LicenseKeyException with LicenseKeyStatus::INVALID_FORMAT
     */
    public function decode(string $licenseKey): array
    {
        if ('' === $licenseKey || strlen($licenseKey) > self::MAX_KEY_LENGTH) {
            throw new LicenseKeyException('License key is empty or too large.', LicenseKeyStatus::INVALID_FORMAT);
        }

        $parts = explode('.', $licenseKey);
        if (3 !== count($parts) || self::KEY_PREFIX !== $parts[0] || '' === $parts[1] || '' === $parts[2]) {
            throw new LicenseKeyException('License key format is invalid.', LicenseKeyStatus::INVALID_FORMAT);
        }

        try {
            $payloadRaw = $this->decodeBase64Url($parts[1]);
            $signature = $this->decodeBase64Url($parts[2]);
        } catch (\Throwable) {
            throw new LicenseKeyException('License key payload or signature is not valid Base64URL.', LicenseKeyStatus::INVALID_FORMAT);
        }

        if (strlen($payloadRaw) > self::MAX_PAYLOAD_LENGTH) {
            throw new LicenseKeyException('License key payload is too large.', LicenseKeyStatus::INVALID_FORMAT);
        }

        return [
            'signed_content' => self::KEY_PREFIX.'.'.$parts[1],
            'signature' => $signature,
            'payload_raw' => $payloadRaw,
        ];
    }

    /**
     * Decodes the signed payload JSON into its raw structure without
     * validating semantics.
     *
     * @return array<string, mixed>
     *
     * @throws LicenseKeyException with LicenseKeyStatus::INVALID_PAYLOAD
     */
    public function decodePayload(string $payloadRaw): array
    {
        try {
            $payload = json_decode($payloadRaw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new LicenseKeyException('License key payload is not valid JSON.', LicenseKeyStatus::INVALID_PAYLOAD);
        }

        if (!is_array($payload) || [] === $payload || array_is_list($payload)) {
            throw new LicenseKeyException('License key payload must be a JSON object.', LicenseKeyStatus::INVALID_PAYLOAD);
        }

        $objectPayload = [];
        foreach ($payload as $key => $value) {
            $objectPayload[(string) $key] = $value;
        }

        return $objectPayload;
    }

    private function encodeBase64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function decodeBase64Url(string $data): string
    {
        return sodium_base642bin($data, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
    }
}

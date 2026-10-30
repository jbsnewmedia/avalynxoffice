<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Security;

/**
 * Symmetric encryption of secrets at rest (connector passwords, ...).
 *
 * Uses sodium_crypto_secretbox with a random nonce per value. Encrypted
 * values carry the prefix "enc:v1:" followed by base64(nonce . ciphertext).
 * Values without that prefix are treated as plaintext and passed through
 * unchanged by decrypt().
 *
 * The key comes from the ENV variable AVALYNXOFFICE_ENCRYPTION_KEY
 * (base64-encoded 32 bytes, e.g. `openssl rand -base64 32`) and is only
 * validated on first use, never on container boot.
 */
final readonly class SecretCrypto
{
    public const string PREFIX = 'enc:v1:';

    public function __construct(
        private string $encryptionKey,
    ) {
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key());

        return self::PREFIX.base64_encode($nonce.$ciphertext);
    }

    /**
     * Decrypts an "enc:v1:" value. Plaintext values (without prefix) are
     * returned unchanged.
     *
     * @throws \RuntimeException when the value cannot be decrypted (wrong key, corrupted data)
     */
    public function decrypt(string $value): string
    {
        if (!$this->isEncrypted($value)) {
            return $value;
        }

        $raw = base64_decode(substr($value, \strlen(self::PREFIX)), true);
        if (false === $raw || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Encrypted value is malformed and cannot be decrypted.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key());
        if (false === $plaintext) {
            throw new \RuntimeException('Encrypted value cannot be decrypted: wrong AVALYNXOFFICE_ENCRYPTION_KEY or corrupted data.');
        }

        return $plaintext;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Encrypts the given keys of the config array. Only non-empty string
     * values that are not yet encrypted are touched.
     *
     * @param array<string, mixed> $config
     * @param list<string>         $keys
     *
     * @return array<string, mixed>
     */
    public function encryptKeys(array $config, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $config[$key] ?? null;
            if (!\is_string($value) || '' === $value || $this->isEncrypted($value)) {
                continue;
            }
            $config[$key] = $this->encrypt($value);
        }

        return $config;
    }

    /**
     * Decrypts the given keys of the config array. Only encrypted string
     * values are touched.
     *
     * @param array<string, mixed> $config
     * @param list<string>         $keys
     *
     * @return array<string, mixed>
     */
    public function decryptKeys(array $config, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $config[$key] ?? null;
            if (!\is_string($value) || !$this->isEncrypted($value)) {
                continue;
            }
            $config[$key] = $this->decrypt($value);
        }

        return $config;
    }

    /**
     * @throws \RuntimeException when the key is missing or invalid
     */
    private function key(): string
    {
        $encoded = trim($this->encryptionKey);
        if ('' === $encoded) {
            throw new \RuntimeException('AVALYNXOFFICE_ENCRYPTION_KEY is not set. Generate one with `openssl rand -base64 32` and configure it in the environment.');
        }

        $key = base64_decode($encoded, true);
        if (false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \RuntimeException(\sprintf('AVALYNXOFFICE_ENCRYPTION_KEY is invalid: expected base64-encoded %d bytes (generate with `openssl rand -base64 32`).', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        }

        return $key;
    }
}

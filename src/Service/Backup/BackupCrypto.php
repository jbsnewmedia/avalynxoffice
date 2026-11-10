<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Backup;

/**
 * Password-based streaming encryption for backup archives.
 *
 * Uses sodium-native primitives: the file is encrypted with
 * XChaCha20-Poly1305 in secretstream mode (authenticated encryption per
 * chunk), the 32 byte key is derived from the admin password with
 * Argon2id (moderate work factors, stored in the header so they can be
 * raised later without breaking old archives).
 *
 * Binary format (all integers big endian):
 *   magic    8 bytes  "AOBAKEN1"
 *   version  4 bytes  format version (currently 1)
 *   ops      4 bytes  Argon2id opslimit
 *   mem      4 bytes  Argon2id memlimit in bytes
 *   salt    16 bytes  random Argon2id salt
 *   header  24 bytes  secretstream header
 *   length   8 bytes  total plaintext size (integrity/completeness check)
 *   chunks   repeated 4-byte length + ciphertext chunk (final chunk sets
 *            the secretstream TAG_FINAL)
 *
 * The plaintext length doubles as the completeness check: a truncated or
 * manipulated archive never decrypts to the promised length, so corrupt
 * downloads are caught. Wrong passwords fail with a distinct exception
 * message, so the UI can tell both apart.
 */
final readonly class BackupCrypto
{
    public const string MAGIC = 'AOBAKEN1';
    public const int CHUNK_SIZE = 65536;
    private const int VERSION = 1;
    private const int OPSLIMIT = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
    private const int MEMLIMIT = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
    private const int KEY_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;
    private const int SALT_BYTES = SODIUM_CRYPTO_PWHASH_SALTBYTES;
    private const int HEADER_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
    private const int CHUNK_OVERHEAD = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
    private const int MIN_MEMLIMIT = 8 * 1024 * 1024;
    private const int MAX_MEMLIMIT = 4 * 1024 * 1024 * 1024;
    private const int MAX_OPSLIMIT = 64;

    /**
     * Whether the file at the given path starts with the encrypted backup
     * magic. Missing files are reported as not encrypted.
     */
    public function isEncryptedFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return false;
        }

        try {
            return self::MAGIC === $this->readExact($handle, 8);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Encrypts the file at $source to $target using the password.
     *
     * @throws \RuntimeException when a file cannot be read or written
     */
    public function encryptFile(string $source, string $target, string $password): void
    {
        $size = filesize($source);
        if (false === $size) {
            throw new \RuntimeException('Backup archive cannot be read for encryption.');
        }

        $in = @fopen($source, 'rb');
        if (false === $in) {
            throw new \RuntimeException('Backup archive cannot be read for encryption.');
        }

        $out = @fopen($target, 'wb');
        if (false === $out) {
            fclose($in);
            throw new \RuntimeException('Encrypted backup cannot be written.');
        }

        try {
            $salt = random_bytes(self::SALT_BYTES);
            $key = $this->deriveKey($password, $salt, self::OPSLIMIT, self::MEMLIMIT);
            $push = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            sodium_memzero($key);
            if (!isset($push[0], $push[1]) || !is_string($push[0]) || !is_string($push[1])) {
                throw new \RuntimeException('Backup encryption failed to initialise.');
            }
            $state = $push[0];
            $header = $push[1];

            fwrite($out, self::MAGIC);
            fwrite($out, pack('N', self::VERSION));
            fwrite($out, pack('N', self::OPSLIMIT));
            fwrite($out, pack('N', self::MEMLIMIT));
            fwrite($out, $salt);
            fwrite($out, $header);
            fwrite($out, pack('J', $size));

            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK_SIZE);
                if (false === $chunk) {
                    throw new \RuntimeException('Backup archive cannot be read for encryption.');
                }

                $final = feof($in);
                $tag = $final
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);

                fwrite($out, pack('N', strlen($cipher)));
                fwrite($out, $cipher);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Decrypts the file at $source to $target using the password.
     *
     * @throws \RuntimeException when the file is not an encrypted backup,
     *                           uses an unsupported format version, is
     *                           corrupted/truncated, or the password is wrong
     */
    public function decryptFile(string $source, string $target, string $password): void
    {
        $in = @fopen($source, 'rb');
        if (false === $in) {
            throw new \RuntimeException('Encrypted backup cannot be read.');
        }

        try {
            if (self::MAGIC !== $this->readExact($in, 8)) {
                throw new \RuntimeException('This file is not an encrypted AvalynxOffice backup.');
            }

            $version = $this->readUint32($in);
            if (self::VERSION !== $version) {
                throw new \RuntimeException('Encrypted backup uses an unsupported format version.');
            }

            $ops = $this->readUint32($in);
            $mem = $this->readUint32($in);
            if ($ops < 1 || $ops > self::MAX_OPSLIMIT || $mem < self::MIN_MEMLIMIT || $mem > self::MAX_MEMLIMIT) {
                throw new \RuntimeException('Encrypted backup carries invalid key derivation parameters.');
            }

            $salt = $this->readExact($in, self::SALT_BYTES);
            $header = $this->readExact($in, self::HEADER_BYTES);
            $originalLength = $this->readUint64($in);
            if ($originalLength < 0) {
                throw new \RuntimeException('Encrypted backup is corrupted (invalid length header).');
            }

            $key = $this->deriveKey($password, $salt, $ops, $mem);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            sodium_memzero($key);

            $out = @fopen($target, 'wb');
            if (false === $out) {
                throw new \RuntimeException('Decrypted backup cannot be written.');
            }

            try {
                $decrypted = 0;
                $finalSeen = false;
                while (true) {
                    // A clean end-of-file only shows up as an empty read
                    // (feof is raised *after* a past-EOF read attempt), so
                    // an empty length read ends the loop, a partial one is
                    // a truncated archive.
                    $lengthRaw = fread($in, 4);
                    if (false === $lengthRaw || '' === $lengthRaw) {
                        break;
                    }

                    if (4 !== strlen($lengthRaw)) {
                        throw new \RuntimeException('Encrypted backup is truncated.');
                    }

                    $unpacked = unpack('N', $lengthRaw);
                    if (false === $unpacked || !isset($unpacked[1]) || !is_int($unpacked[1])) {
                        throw new \RuntimeException('Encrypted backup is corrupted.');
                    }

                    $length = $unpacked[1];
                    if ($length < self::CHUNK_OVERHEAD || $length > self::CHUNK_SIZE + self::CHUNK_OVERHEAD) {
                        throw new \RuntimeException('Encrypted backup is corrupted.');
                    }

                    $cipher = $this->readExact($in, $length);
                    // PHP 8.5 pull returns [plaintext, tag] tuples (the
                    // separate retag function was removed); false signals a
                    // wrong key or corruption.
                    $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                    if (!is_array($pulled) || !isset($pulled[0], $pulled[1]) || !is_string($pulled[0])) {
                        throw new \RuntimeException('Backup cannot be decrypted: wrong password or corrupted file.');
                    }

                    if ($finalSeen) {
                        throw new \RuntimeException('Encrypted backup is corrupted (data after final chunk).');
                    }

                    fwrite($out, $pulled[0]);
                    $decrypted += strlen($pulled[0]);
                    $finalSeen = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $pulled[1];
                }

                if (!$finalSeen || $decrypted !== $originalLength) {
                    throw new \RuntimeException('Encrypted backup is truncated or corrupted.');
                }
            } finally {
                fclose($out);
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * @return array{opslimit: int, memlimit: int}
     */
    public function keyDerivationParameters(): array
    {
        return ['opslimit' => self::OPSLIMIT, 'memlimit' => self::MEMLIMIT];
    }

    private function deriveKey(string $password, string $salt, int $ops, int $mem): string
    {
        return sodium_crypto_pwhash(
            self::KEY_BYTES,
            $password,
            $salt,
            $ops,
            $mem,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    /**
     * @param resource    $handle
     * @param int<1, max> $bytes
     */
    private function readExact($handle, int $bytes): string
    {
        $data = fread($handle, $bytes);
        if (false === $data || strlen($data) !== $bytes) {
            throw new \RuntimeException('Encrypted backup is truncated or not readable.');
        }

        return $data;
    }

    /**
     * @param resource $handle
     */
    private function readUint32($handle): int
    {
        $unpacked = unpack('N', $this->readExact($handle, 4));
        if (false === $unpacked || !isset($unpacked[1]) || !is_int($unpacked[1])) {
            throw new \RuntimeException('Encrypted backup is corrupted.');
        }

        return $unpacked[1];
    }

    /**
     * @param resource $handle
     */
    private function readUint64($handle): int
    {
        $unpacked = unpack('J', $this->readExact($handle, 8));
        if (false === $unpacked || !isset($unpacked[1]) || !is_int($unpacked[1])) {
            throw new \RuntimeException('Encrypted backup is corrupted.');
        }

        return $unpacked[1];
    }
}

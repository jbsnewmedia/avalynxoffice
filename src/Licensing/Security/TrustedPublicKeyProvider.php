<?php

declare(strict_types=1);

namespace App\Licensing\Security;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Provides the trusted Ed25519 public verification keys, mapped by key_id.
 *
 * The mapping comes exclusively from the trusted application configuration
 * (AVALYNXOFFICE_LICENSE_PUBLIC_KEYS), never from unvalidated license data.
 */
final class TrustedPublicKeyProvider
{
    /**
     * @var array<string, string>
     */
    private array $publicKeys = [];

    public function __construct(
        string $publicKeysJson,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->publicKeys = $this->parse($publicKeysJson);
    }

    public function getPublicKeyFor(string $keyId): ?string
    {
        return $this->publicKeys[$keyId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getKeyIds(): array
    {
        return array_keys($this->publicKeys);
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $publicKeysJson): array
    {
        $trimmed = trim($publicKeysJson);
        if ('' === $trimmed) {
            return [];
        }

        try {
            $decoded = json_decode($trimmed, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning('AVALYNXOFFICE_LICENSE_PUBLIC_KEYS is not valid JSON, no trusted license keys are configured.');

            return [];
        }

        if (!is_array($decoded)) {
            $this->logger->warning('AVALYNXOFFICE_LICENSE_PUBLIC_KEYS must be a JSON object mapping key_id to public key.');

            return [];
        }

        $keys = [];
        foreach ($decoded as $keyId => $publicKey) {
            if (!is_string($keyId) || '' === $keyId || !is_string($publicKey)) {
                $this->logger->warning('Ignoring invalid entry in AVALYNXOFFICE_LICENSE_PUBLIC_KEYS.');

                continue;
            }

            $raw = $this->decodePublicKey($publicKey);
            if (null === $raw) {
                $this->logger->warning(sprintf('Ignoring public license key "%s": it must be a Base64 encoded Ed25519 public key.', $keyId));

                continue;
            }

            $keys[$keyId] = $raw;
        }

        return $keys;
    }

    private function decodePublicKey(string $publicKey): ?string
    {
        $raw = base64_decode(strtr($publicKey, '-_', '+/'), true);
        if (false === $raw || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen($raw)) {
            return null;
        }

        return $raw;
    }
}

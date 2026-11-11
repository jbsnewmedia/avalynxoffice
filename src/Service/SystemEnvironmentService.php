<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Detects the runtime environment of the installation and the
 * availability of the EAS (ActiveSync) endpoint for the system status
 * page.
 *
 * Docker detection: the /.dockerenv marker file exists inside every
 * Docker container (and the Podman equivalent). The path is injectable
 * so tests can point it at a fixture file.
 *
 * EAS availability: an unauthenticated GET on
 * <base url>/Microsoft-Server-ActiveSync must yield any HTTP response —
 * usually the 401 basic-auth challenge — which proves the service is
 * reachable. A transport error (DNS failure, connection refused, timeout)
 * means it is not. The probe result is memoized for 30 seconds so the
 * 10 second live-refresh poll of the system status page does not hammer
 * the EAS service on every poll.
 */
final class SystemEnvironmentService
{
    private const string EAS_PROBE_PATH = '/Microsoft-Server-ActiveSync';
    private const int PROBE_TIMEOUT_SECONDS = 2;
    private const int CACHE_SECONDS = 30;

    private readonly HttpClientInterface $httpClient;

    /**
     * @var array{configured: bool, available: bool, url: string, statusCode: int|null, latencyMs: int|null, detail: string}|null
     */
    private ?array $easCache = null;

    private int $easCacheAt = 0;

    public function __construct(
        private readonly string $dockerEnvFile = '/.dockerenv',
        private readonly ?string $easBaseUrl = null,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => self::PROBE_TIMEOUT_SECONDS,
            'max_duration' => self::PROBE_TIMEOUT_SECONDS,
        ]);
    }

    public function isDocker(): bool
    {
        return file_exists($this->dockerEnvFile);
    }

    /**
     * @return array{configured: bool, available: bool, url: string|null, statusCode: int|null, latencyMs: int|null, detail: string}
     */
    public function easStatus(): array
    {
        $baseUrl = trim((string) $this->easBaseUrl);
        if ('' === $baseUrl) {
            return [
                'configured' => false,
                'available' => false,
                'url' => null,
                'statusCode' => null,
                'latencyMs' => null,
                'detail' => 'not_configured',
            ];
        }

        $now = time();
        if (null !== $this->easCache && $now - $this->easCacheAt < self::CACHE_SECONDS) {
            return $this->easCache;
        }

        $this->easCache = $this->probe(rtrim($baseUrl, '/').self::EAS_PROBE_PATH);
        $this->easCacheAt = $now;

        return $this->easCache;
    }

    /**
     * @return array{configured: bool, available: bool, url: string, statusCode: int|null, latencyMs: int|null, detail: string}
     */
    private function probe(string $url): array
    {
        $start = microtime(true);

        try {
            // throw=false: any status code (the unauthenticated probe
            // normally answers with the 401 challenge) proves the service
            // is up; only transport errors throw and mean "not available".
            $response = $this->httpClient->request('GET', $url, ['throw' => false]);
            $statusCode = $response->getStatusCode();
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            return [
                'configured' => true,
                'available' => true,
                'url' => $url,
                'statusCode' => $statusCode,
                'latencyMs' => $latencyMs,
                'detail' => 'http_'.$statusCode,
            ];
        } catch (\Throwable) {
            return [
                'configured' => true,
                'available' => false,
                'url' => $url,
                'statusCode' => null,
                'latencyMs' => (int) round((microtime(true) - $start) * 1000),
                'detail' => 'unreachable',
            ];
        }
    }
}

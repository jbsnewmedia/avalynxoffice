<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Login;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves the approximate location (city, country) of a login IP via the
 * free ip-api.com service. Lookups are best-effort: every failure degrades
 * to unknown location, never to an error.
 *
 * The free tier of ip-api.com is only reachable over plain HTTP, so the
 * lookup is opt-in (AVALYNXOFFICE_GEOIP_ENABLED, default off) and uses a
 * short timeout so a slow or unreachable service never delays the login.
 */
class GeoIpLookupService
{
    private const string ENDPOINT = 'http://ip-api.com/json/';
    private const int TIMEOUT_SECONDS = 1;

    private readonly HttpClientInterface $httpClient;

    public function __construct(
        ?HttpClientInterface $httpClient = null,
        private readonly bool $enabled = false,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => self::TIMEOUT_SECONDS, 'max_duration' => self::TIMEOUT_SECONDS]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array{city: ?string, country: ?string}
     */
    public function lookup(string $ip): array
    {
        // Private and reserved ranges are never sent to the lookup service.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['city' => null, 'country' => 'Lokales Netzwerk'];
        }

        if (!$this->enabled) {
            return ['city' => null, 'country' => null];
        }

        try {
            $geo = $this->fetchGeo($ip);
        } catch (\Throwable) {
            $geo = null;
        }

        return [
            'city' => $geo['city'] ?? null,
            'country' => $geo['country'] ?? null,
        ];
    }

    /**
     * @return array{status: string, city: string|null, country: string|null}|null
     */
    protected function fetchGeo(string $ip): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT.rawurlencode($ip), [
                'query' => ['fields' => 'status,city,country'],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $status = $data['status'] ?? null;
        if (!is_string($status) || 'success' !== $status) {
            return null;
        }

        $city = is_string($data['city'] ?? null) ? $data['city'] : null;
        $country = is_string($data['country'] ?? null) ? $data['country'] : null;

        return ['status' => $status, 'city' => $city, 'country' => $country];
    }
}

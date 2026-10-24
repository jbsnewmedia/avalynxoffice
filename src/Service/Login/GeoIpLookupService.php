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
 */
class GeoIpLookupService
{
    private const string ENDPOINT = 'http://ip-api.com/json/';
    private const int TIMEOUT_SECONDS = 2;

    private readonly ?HttpClientInterface $httpClient;

    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => self::TIMEOUT_SECONDS]);
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
     * @return array{status?: string, city?: string, country?: string}|null
     */
    protected function fetchGeo(string $ip): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT.rawurlencode($ip), [
                'query' => ['fields' => 'status,city,country'],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $data = $response->toArray();
        } catch (\Throwable) {
            return null;
        }

        if (($data['status'] ?? '') !== 'success') {
            return null;
        }

        return $data;
    }
}

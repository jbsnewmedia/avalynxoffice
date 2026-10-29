<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Login;

use AvalynxOffice\Service\Login\GeoIpLookupService;
use PHPUnit\Framework\TestCase;

final class GeoIpLookupServiceTest extends TestCase
{
    public function testPrivateAndReservedIpsResolveToLocalNetworkWithoutLookup(): void
    {
        $service = new class extends GeoIpLookupService {
            public bool $fetched = false;

            #[\Override]
            protected function fetchGeo(string $ip): ?array
            {
                $this->fetched = true;

                return null;
            }
        };
        $instance = new $service();
        $instance->fetched = false;

        $result = $instance->lookup('192.168.1.10');

        self::assertSame(['city' => null, 'country' => 'Lokales Netzwerk'], $result);
        self::assertFalse($instance->fetched, 'Private IPs must not hit the lookup service');
    }

    public function testSuccessfulLookupReturnsCityAndCountry(): void
    {
        $service = new class extends GeoIpLookupService {
            public function __construct()
            {
                parent::__construct(null);
            }

            #[\Override]
            protected function fetchGeo(string $ip): ?array
            {
                return ['status' => 'success', 'city' => 'Mainz', 'country' => 'Germany'];
            }
        };

        $result = $service->lookup('81.2.69.160');

        self::assertSame(['city' => 'Mainz', 'country' => 'Germany'], $result);
    }

    public function testFailedLookupReturnsUnknownLocation(): void
    {
        $service = new class extends GeoIpLookupService {
            public function __construct()
            {
                parent::__construct(null);
            }

            #[\Override]
            protected function fetchGeo(string $ip): ?array
            {
                return ['status' => 'fail'];
            }
        };

        $result = $service->lookup('81.2.69.160');

        self::assertSame(['city' => null, 'country' => null], $result);
    }

    public function testLookupExceptionReturnsUnknownLocation(): void
    {
        $service = new class extends GeoIpLookupService {
            public function __construct()
            {
                parent::__construct(null);
            }

            #[\Override]
            protected function fetchGeo(string $ip): ?array
            {
                throw new \RuntimeException('network down');
            }
        };

        $result = $service->lookup('81.2.69.160');

        self::assertSame(['city' => null, 'country' => null], $result);
    }

    public function testRealFetchGeoSwallowsHttpClientFailures(): void
    {
        $httpClient = $this->createStub(\Symfony\Contracts\HttpClient\HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new \RuntimeException('dns kaputt'));

        $service = new GeoIpLookupService($httpClient);

        self::assertSame(['city' => null, 'country' => null], $service->lookup('81.2.69.160'));
    }

    public function testRealFetchGeoReturnsUnknownLocationOnNonSuccessStatus(): void
    {
        $response = $this->createStub(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('toArray')->willReturn(['status' => 'fail']);

        $httpClient = $this->createStub(\Symfony\Contracts\HttpClient\HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $service = new GeoIpLookupService($httpClient);

        self::assertSame(['city' => null, 'country' => null], $service->lookup('81.2.69.160'));
    }

    public function testRealFetchGeoReturnsCityAndCountryOnSuccess(): void
    {
        $response = $this->createStub(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('toArray')->willReturn(['status' => 'success', 'city' => 'Mainz', 'country' => 'Germany']);

        $httpClient = $this->createStub(\Symfony\Contracts\HttpClient\HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $service = new GeoIpLookupService($httpClient);

        self::assertSame(['city' => 'Mainz', 'country' => 'Germany'], $service->lookup('81.2.69.160'));
    }
}

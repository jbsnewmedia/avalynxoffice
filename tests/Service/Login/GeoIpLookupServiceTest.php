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
                parent::__construct(null, true);
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

    public function testLookupIsDisabledByDefaultAndNeverCallsTheService(): void
    {
        $service = new class extends GeoIpLookupService {
            public bool $fetched = false;

            public function __construct()
            {
                parent::__construct(null);
            }

            #[\Override]
            protected function fetchGeo(string $ip): ?array
            {
                $this->fetched = true;

                return ['status' => 'success', 'city' => 'Mainz', 'country' => 'Germany'];
            }
        };

        self::assertFalse($service->isEnabled());
        self::assertSame(['city' => null, 'country' => null], $service->lookup('81.2.69.160'));
        self::assertFalse($service->fetched, 'Disabled lookups must not contact the external service');
    }

    public function testDisabledLookupNeverUsesTheHttpClient(): void
    {
        $httpClient = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $service = new GeoIpLookupService($httpClient, false);

        self::assertSame(['city' => null, 'country' => null], $service->lookup('81.2.69.160'));
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

        $service = new GeoIpLookupService($httpClient, true);

        self::assertSame(['city' => null, 'country' => null], $service->lookup('81.2.69.160'));
    }

    public function testRealFetchGeoReturnsUnknownLocationOnNonSuccessStatus(): void
    {
        $response = $this->createStub(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('toArray')->willReturn(['status' => 'fail']);

        $httpClient = $this->createStub(\Symfony\Contracts\HttpClient\HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $service = new GeoIpLookupService($httpClient, true);

        self::assertSame(['city' => null, 'country' => null], $service->lookup('81.2.69.160'));
    }

    public function testRealFetchGeoReturnsCityAndCountryOnSuccess(): void
    {
        $response = $this->createStub(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('toArray')->willReturn(['status' => 'success', 'city' => 'Mainz', 'country' => 'Germany']);

        $httpClient = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')->with(
            'GET',
            'http://ip-api.com/json/81.2.69.160',
            self::callback(static fn (array $options): bool => 1 === ($options['timeout'] ?? null) && 1 === ($options['max_duration'] ?? null)),
        )->willReturn($response);

        $service = new GeoIpLookupService($httpClient, true);

        self::assertSame(['city' => 'Mainz', 'country' => 'Germany'], $service->lookup('81.2.69.160'));
    }
}

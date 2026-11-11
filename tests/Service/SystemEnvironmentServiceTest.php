<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Service\SystemEnvironmentService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SystemEnvironmentServiceTest extends TestCase
{
    public function testDockerDetectedViaMarkerFile(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'dockerenv-');
        \assert(is_string($marker));
        $service = new SystemEnvironmentService($marker);

        try {
            self::assertTrue($service->isDocker());
        } finally {
            @unlink($marker);
        }
    }

    public function testDockerAbsentMeansNativeRuntime(): void
    {
        $service = new SystemEnvironmentService(sys_get_temp_dir().'/does-not-exist-'.bin2hex(random_bytes(4)));

        self::assertFalse($service->isDocker());
    }

    public function testEasStatusWithoutConfigurationReportsNotConfigured(): void
    {
        $httpClient = new MockHttpClient([]);
        $service = new SystemEnvironmentService('/.dockerenv', null, $httpClient);

        $status = $service->easStatus();

        self::assertFalse($status['configured']);
        self::assertFalse($status['available']);
        self::assertNull($status['url']);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testEasStatusProbeAnswersWithHttpStatusMeansAvailable(): void
    {
        // The unauthenticated probe normally gets the 401 basic auth
        // challenge — any status code proves the service is up.
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 401]),
        ]);
        $service = new SystemEnvironmentService('/.dockerenv', 'http://eas:80', $httpClient);

        $status = $service->easStatus();

        self::assertTrue($status['configured']);
        self::assertTrue($status['available']);
        self::assertSame(401, $status['statusCode']);
        self::assertSame('http://eas:80/Microsoft-Server-ActiveSync', $status['url']);
        self::assertNotNull($status['latencyMs']);
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testEasStatusProbeIsMemoizedWithinCacheWindow(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 401]),
        ]);
        $service = new SystemEnvironmentService('/.dockerenv', 'http://eas:80', $httpClient);

        $service->easStatus();
        $second = $service->easStatus();

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertTrue($second['available']);
    }

    public function testEasStatusTransportFailureMeansUnavailable(): void
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url): MockResponse {
            throw new TransportException('Connection refused');
        });
        $service = new SystemEnvironmentService('/.dockerenv', 'http://eas:80', $httpClient);

        $status = $service->easStatus();

        self::assertTrue($status['configured']);
        self::assertFalse($status['available']);
        self::assertNull($status['statusCode']);
        self::assertSame('unreachable', $status['detail']);
    }

    public function testTrailingSlashOfBaseUrlIsNormalized(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 405]),
        ]);
        $service = new SystemEnvironmentService('/.dockerenv', 'http://eas:80/', $httpClient);

        $status = $service->easStatus();

        self::assertSame('http://eas:80/Microsoft-Server-ActiveSync', $status['url']);
        self::assertSame(405, $status['statusCode']);
    }
}

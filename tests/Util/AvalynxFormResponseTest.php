<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Util;

use AvalynxOffice\Util\AvalynxFormResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

final class AvalynxFormResponseTest extends TestCase
{
    public function testSuccessWithoutRedirectRendersSuccessPayload(): void
    {
        $response = AvalynxFormResponse::success();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSuccessWithRedirectAndExtraMergesThePayload(): void
    {
        $response = AvalynxFormResponse::success('/next', ['refresh' => true]);

        self::assertSame(
            ['success' => true, 'redirect' => '/next', 'refresh' => true],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testInvalidRendersFieldErrorsWithUnprocessableStatus(): void
    {
        $response = AvalynxFormResponse::invalid(['title' => 'Titel darf nicht leer sein.']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ['success' => false, 'invalid' => ['title' => 'Titel darf nicht leer sein.']],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testInvalidWithMessageAddsTheMessageToThePayload(): void
    {
        $response = AvalynxFormResponse::invalid(['title' => 'Titel darf nicht leer sein.'], 'Bitte prüfe die Eingaben.');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            [
                'success' => false,
                'invalid' => ['title' => 'Titel darf nicht leer sein.'],
                'message' => 'Bitte prüfe die Eingaben.',
            ],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testResponsesAreJsonResponses(): void
    {
        self::assertInstanceOf(JsonResponse::class, AvalynxFormResponse::success());
        self::assertInstanceOf(JsonResponse::class, AvalynxFormResponse::invalid([]));
    }
}

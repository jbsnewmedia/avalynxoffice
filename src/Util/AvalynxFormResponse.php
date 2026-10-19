<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Util;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * JSON responses in the AvalynxForm schema (https://github.com/avalynx/avalynx-form):
 * - success:true + optional redirect → AvalynxForm follows the redirect client-side
 * - success:false + invalid map      → field-level feedback via .invalid-feedback
 * - success:false + message          → shown by the onError wiring (AvalynxAlert)
 */
final readonly class AvalynxFormResponse
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function success(?string $redirect = null, array $extra = []): JsonResponse
    {
        $payload = ['success' => true];

        if (null !== $redirect) {
            $payload['redirect'] = $redirect;
        }

        return new JsonResponse([...$payload, ...$extra]);
    }

    /**
     * @param array<string, string> $invalid input name → error message
     */
    public static function invalid(array $invalid, ?string $message = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'invalid' => $invalid,
        ];

        if (null !== $message) {
            $payload['message'] = $message;
        }

        return new JsonResponse($payload, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function failure(string $message, int $status = JsonResponse::HTTP_FORBIDDEN): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}

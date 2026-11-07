<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class Api
{
    public static function ok(mixed $data = null, string $message = 'ok'): JsonResponse
    {
        return self::response(0, $message, $data);
    }

    public static function fail(int $code, string $message, mixed $data = null, int $http = 400): JsonResponse
    {
        return self::response($code, $message, $data, $http);
    }

    private static function response(int $code, string $message, mixed $data, int $http = 200): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'request_id' => (string) Str::uuid(),
        ], $http);
    }
}

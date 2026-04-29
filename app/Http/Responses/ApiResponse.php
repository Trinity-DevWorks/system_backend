<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Stable JSON API envelope (same contract as inventory-management-backend).
 *
 * Success: { success, status, message, data, meta? }
 * Error:   { success, status, message, code?, data?, errors?, error_type?, details? }
 *
 * `status` mirrors `success` (bool) for backward compatibility with older clients.
 */
final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $statusCode = 200,
        ?array $meta = null
    ): JsonResponse {
        $body = [
            'success' => true,
            'status' => true,
            'message' => $message,
            'data' => $data,
        ];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $statusCode);
    }

    public static function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    /**
     * @param  array<string, mixed>|string  $errors  Validation errors or error payload
     */
    public static function error(
        string $message,
        int $statusCode = 400,
        mixed $data = null,
        array|string $errors = [],
        ?string $errorType = null,
        mixed $details = null,
        ?string $code = null
    ): JsonResponse {
        $resolvedCode = ($code !== null && $code !== '')
            ? $code
            : self::defaultErrorCodeForStatus($statusCode);

        $body = [
            'success' => false,
            'status' => false,
            'message' => $message,
            'data' => $data,
            'code' => $resolvedCode,
        ];
        if ($errors !== [] && $errors !== '') {
            $body['errors'] = $errors;
        }
        if ($errorType !== null) {
            $body['error_type'] = $errorType;
        }
        if ($details !== null) {
            $body['details'] = $details;
        }

        return response()->json($body, $statusCode);
    }

    public static function validationFailed(
        array $errors,
        string $message = 'Validation failed.',
        string $code = 'VALIDATION_ERROR'
    ): JsonResponse {
        return self::error($message, 422, null, $errors, null, null, $code);
    }

    public static function notFound(
        string $message = 'Resource not found.',
        string $code = 'NOT_FOUND'
    ): JsonResponse
    {
        return self::error($message, 404, null, [], null, null, $code);
    }

    public static function forbidden(
        string $message = 'Forbidden.',
        string $code = 'FORBIDDEN'
    ): JsonResponse
    {
        return self::error($message, 403, null, [], null, null, $code);
    }

    private static function defaultErrorCodeForStatus(int $statusCode): string
    {
        return match ($statusCode) {
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            default => 'REQUEST_FAILED',
        };
    }
}

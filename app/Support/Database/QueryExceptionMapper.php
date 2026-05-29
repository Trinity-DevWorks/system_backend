<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Http\Responses\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * Maps PostgreSQL integrity errors to stable API conflict responses.
 *
 * Used as a safety net when domain rules do not pre-check a foreign key.
 */
final class QueryExceptionMapper
{
    private const SQLSTATE_FOREIGN_KEY_VIOLATION = '23503';

    public static function tryForeignKeyViolationResponse(QueryException $exception): ?JsonResponse
    {
        if (! self::isForeignKeyViolation($exception)) {
            return null;
        }

        [$message, $code] = self::messageAndCode($exception);

        return ApiResponse::error($message, 409, null, [], null, null, $code);
    }

    public static function isForeignKeyViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return $sqlState === self::SQLSTATE_FOREIGN_KEY_VIOLATION;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function messageAndCode(QueryException $exception): array
    {
        $detail = (string) ($exception->errorInfo[2] ?? '');

        if (str_contains($detail, 'is still referenced')) {
            return [
                'Cannot delete this record because it is still linked to other data.',
                'DELETE_REFERENCED',
            ];
        }

        if (str_contains($detail, 'is not present in table')) {
            return [
                'The related record does not exist or is invalid.',
                'FOREIGN_KEY_INVALID_REFERENCE',
            ];
        }

        return [
            'This action conflicts with related records.',
            'FOREIGN_KEY_VIOLATION',
        ];
    }
}

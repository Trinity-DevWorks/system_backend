<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleCors::class);
        // Omit statefulApi(): it applies session + CSRF to /api for SANCTUM_STATEFUL_DOMAINS.
        // This app uses Bearer tokens from createToken(), not cookie session auth — enable
        // statefulApi() only if you add the SPA flow: GET /sanctum/csrf-cookie then POST with X-XSRF-TOKEN.
        $middleware->alias([
            'check.permission' => CheckPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $wantsEnvelope = static fn (Request $request): bool => $request->expectsJson();

        $exceptions->render(function (ValidationException $e, Request $request) use ($wantsEnvelope) {
            if ($wantsEnvelope($request)) {
                return ApiResponse::validationFailed($e->errors(), $e->getMessage(), 'VALIDATION_ERROR');
            }

        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsEnvelope) {
            if ($wantsEnvelope($request)) {
                return ApiResponse::error('Unauthenticated.', 401, null, [], null, null, 'UNAUTHORIZED');
            }

        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($wantsEnvelope) {
            if ($wantsEnvelope($request)) {
                return ApiResponse::forbidden($e->getMessage() ?: 'Forbidden.', 'FORBIDDEN');
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($wantsEnvelope) {
            if ($wantsEnvelope($request)) {
                return ApiResponse::notFound($e->getMessage() ?: 'Resource not found.', 'NOT_FOUND');
            }

        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($wantsEnvelope) {
            if ($wantsEnvelope($request)) {
                return ApiResponse::notFound('Resource not found.', 'NOT_FOUND');
            }

        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($wantsEnvelope) {
            if ($wantsEnvelope($request)) {
                $code = $e->getHeaders()['X-Error-Code'] ?? null;
                return ApiResponse::error(
                    $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
                    $e->getStatusCode(),
                    null,
                    [],
                    null,
                    null,
                    is_string($code) ? $code : null
                );
            }
        });
    })->create();

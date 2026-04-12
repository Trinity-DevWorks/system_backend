<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        $middleware->statefulApi();
        $middleware->alias([
            'check.permission' => CheckPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $wantsEnvelope = static fn (Request $request): bool => $request->expectsJson();

        $exceptions->render(function (ValidationException $e, Request $request) use ($wantsEnvelope) {
            if ($wantsEnvelope($request)) {
                return ApiResponse::validationFailed($e->errors(), $e->getMessage());
            }

        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsEnvelope) {
            if ($wantsEnvelope($request)) {
                return ApiResponse::error('Unauthenticated.', 401);
            }

        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($wantsEnvelope) {
            if ($wantsEnvelope($request)) {
                return ApiResponse::notFound($e->getMessage() ?: 'Resource not found.');
            }

        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($wantsEnvelope) {
            if ($wantsEnvelope($request)) {
                return ApiResponse::notFound('Resource not found.');
            }

        });
    })->create();

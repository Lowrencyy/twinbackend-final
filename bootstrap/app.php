<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            '/contact-send',
        ]);

        $middleware->alias([
            'company'     => \App\Http\Middleware\EnsureCompany::class,
            'asbuilt.key' => \App\Http\Middleware\AsBuiltApiKey::class,
        ]);

        // Apply to every API route: structured logging + ETag fallback for uncached endpoints
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\ApiRequestLogger::class,
            \App\Http\Middleware\ETagMiddleware::class,
            \App\Http\Middleware\TrackApiStats::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        });
    })->create();

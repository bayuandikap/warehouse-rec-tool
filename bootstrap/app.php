<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Apply the 'api' named rate limiter to all API routes.
        // The limiter itself (60 req/min per IP) is defined in AppServiceProvider.
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Always return JSON for requests to api/* routes, regardless of
        // Accept header — consistent with an API-first response policy.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
        );
    })->create();

<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\StaffOnly;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/*
| Deployment note: on Hostinger, Laravel's public/ folder is served at
| https://crm.digifoxprosolutions.com/api, so Laravel sees request paths
| relative to /api. With apiPrefix 'v1', the public URL of every API route is
| /api/v1/... in production, and /v1/... on the local `php artisan serve`
| (the Angular dev proxy rewrites /api to the local server).
*/
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API only: never redirect guests to a login page, just answer 401.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'permission' => EnsurePermission::class,
            'active' => EnsureAccountActive::class,
            'staff' => StaffOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This app is an API only: always answer with JSON.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
    })->create();

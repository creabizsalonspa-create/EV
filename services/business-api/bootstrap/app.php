<?php

use App\Http\Middleware\AccountStatusGate;
use App\Http\Middleware\SecurityHeaders;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        // A suspended identity is never authorized by an old browser session
        // or a lingering Sanctum token. Authentication is still responsible
        // for deciding whether an identity exists.
        $middleware->web(append: [AccountStatusGate::class]);
        $middleware->api(append: [AccountStatusGate::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->routeIs('verification.verify') ? '/?verification=signin' : '/');
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

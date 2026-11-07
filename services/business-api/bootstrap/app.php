<?php

use App\Http\Middleware\AdminSecondFactorGate;
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
        // Run after the web session/auth middleware so Admin identity and
        // challenge proof are both available before protected handlers execute.
        $middleware->web(append: [AdminSecondFactorGate::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->routeIs('verification.verify') ? '/?verification=signin' : '/');
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

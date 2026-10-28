<?php

use App\Http\Controllers\AccountController as A;
use App\Http\Controllers\ChargerIngressController;
use App\Http\Controllers\ConnectorTopologyController;
use App\Http\Controllers\CsmsSessionController;
use App\Http\Controllers\EnterpriseController as E;
use App\Http\Controllers\MobileAuthController as M;
use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\PlatformController as P;
use App\Http\Controllers\RecurringPaymentController;
use App\Http\Controllers\ReservationLifecycleController;
use App\Http\Controllers\SimulationController as S;
use App\Http\Controllers\SupportController as T;
use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:authentication')->group(function () {
        Route::post('/login', [M::class, 'login']);
        Route::post('/register', [M::class, 'register']);
    });
    Route::get('/stations', [P::class, 'stations'])->middleware('throttle:catalog');
    Route::get('/regions', [E::class, 'regions'])->middleware('throttle:catalog');
    Route::middleware(['auth:sanctum', 'throttle:platform-api'])->group(function () {
        Route::group([], __DIR__.'/finance.php');
        Route::prefix('vendor-ops')->group(__DIR__.'/vendor_ops.php');
        Route::get('/me', fn (Request $r) => response()->json($r->user()));
        Route::post('/logout', [M::class, 'logout']);
        Route::patch('/profile', [A::class, 'profile']);
        Route::get('/vehicles', [P::class, 'vehicles']);
        Route::post('/vehicles', [P::class, 'createVehicle']);
        Route::post('/stations', [P::class, 'createStation']);
        Route::get('/vendor/stations', function (Request $r) {
            abort_unless($r->user()->role === 'vendor', 403);

            return Station::where('vendor_id', $r->user()->id)->orderByDesc('id')->paginate(25);
        });
        Route::get('/reservations', [P::class, 'reservations']);
        Route::post('/reservations', [P::class, 'reserve']);
        Route::delete('/reservations/{reservation}', [P::class, 'cancel']);
        Route::get('/simulations', [S::class, 'index']);
        Route::post('/reservations/{reservation}/simulate-start', [S::class, 'start']);
        Route::put('/simulations/{session}/meter', [S::class, 'meter']);
        Route::post('/simulations/{session}/stop', [S::class, 'stop']);
        Route::get('/fleets', [E::class, 'index']);
        Route::post('/fleets', [E::class, 'create']);
        Route::get('/fleets/{fleet}', [E::class, 'show']);
        Route::get('/fleet-invitations', [E::class, 'invitations']);
        Route::post('/fleet-invitations/{invitation}/accept', [E::class, 'accept']);
    });
    Route::middleware(['auth:sanctum', 'throttle:support'])->group(function () {
        Route::get('/support', [T::class, 'index']);
        Route::post('/support', [T::class, 'create']);
        Route::get('/support/{ticket}', [T::class, 'show']);
        Route::post('/support/{ticket}/messages', [T::class, 'reply']);
        Route::get('/notifications', [T::class, 'notifications']);
        Route::post('/notifications/{notification}/read', [T::class, 'read']);
    });
});

Route::post('/internal/csms/events', [CsmsSessionController::class, 'event'])->middleware('throttle:csms-events');

Route::post('/payments/mock/webhook', [PaymentGatewayController::class, 'webhook'])->middleware('throttle:platform-api');

Route::post('/payments/stripe-test/webhook', [PaymentGatewayController::class, 'stripeWebhook'])->middleware('throttle:platform-api');

require __DIR__.'/subscription_webhooks.php';

Route::get('/v1/stations/{station}/connectors', [ConnectorTopologyController::class, 'options'])->middleware('throttle:catalog');

Route::post('/v1/reservations/{reservation}/check-in', [ReservationLifecycleController::class, 'checkIn'])->middleware(['auth:sanctum', 'throttle:platform-api']);

Route::post('/v1/charging-sessions/{session}/reconcile', [CsmsSessionController::class, 'reconcile'])->middleware(['auth:sanctum', 'throttle:platform-api']);

Route::post('/v1/reservations/{reservation}/csms-start', [CsmsSessionController::class, 'start'])->middleware(['auth:sanctum', 'throttle:platform-api']);
Route::post('/v1/charging-sessions/{session}/csms-stop', [CsmsSessionController::class, 'stop'])->middleware(['auth:sanctum', 'throttle:platform-api']);

Route::post('/internal/charger-ingress/authenticate', [ChargerIngressController::class, 'authenticate'])->middleware('throttle:charger-admission');

Route::middleware(['auth:sanctum', 'throttle:platform-api'])->prefix('v1')->group(__DIR__.'/charging_operations.php');

require __DIR__.'/subscription_credit_webhooks.php';

Route::post('/payment-enrollments/stripe-test/webhook', [RecurringPaymentController::class, 'webhook'])->middleware('throttle:platform-api');

Route::middleware(['auth:sanctum', 'throttle:platform-api'])->prefix('v1')->group(__DIR__.'/favorites.php');

require __DIR__.'/payout_webhooks.php';
require __DIR__.'/external_dispute_webhooks.php';

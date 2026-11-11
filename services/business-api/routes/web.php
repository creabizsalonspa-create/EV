<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ChargerCommandController;
use App\Http\Controllers\ChargingReviewController;
use App\Http\Controllers\ConnectorTopologyController;
use App\Http\Controllers\CsmsSessionController;
use App\Http\Controllers\EnterpriseController;
use App\Http\Controllers\PlatformController as P;
use App\Http\Controllers\ReservationLifecycleController;
use App\Http\Controllers\SimulationController;
use App\Http\Controllers\StationInformationController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\VehicleBatteryController;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::view('/', 'platform');
Route::get('/api/csrf', fn () => response()->json(['token' => csrf_token()]));
Route::middleware('throttle:authentication')->group(function () {
    Route::post('/api/register', [P::class, 'register']);
    Route::post('/api/login', [P::class, 'login']);
});
Route::get('/api/stations', [P::class, 'stations'])->middleware('throttle:catalog');
Route::middleware(['auth', 'throttle:platform-api'])->group(function () {
    Route::get('/api/me', fn (Request $r) => response()->json($r->user()));
    Route::post('/api/logout', [P::class, 'logout']);
    Route::post('/api/stations', [P::class, 'createStation']);
    Route::get('/api/vehicles', [P::class, 'vehicles']);
    Route::post('/api/vehicles', [P::class, 'createVehicle']);
    Route::get('/api/vehicles/{vehicle}/battery-preferences', [VehicleBatteryController::class, 'show']);
    Route::put('/api/vehicles/{vehicle}/battery-preferences', [VehicleBatteryController::class, 'update']);
    Route::post('/api/vehicles/{vehicle}/battery-simulation', [VehicleBatteryController::class, 'simulate']);
    Route::get('/api/reservations', [P::class, 'reservations']);
    Route::post('/api/reservations', [P::class, 'reserve']);
    Route::delete('/api/reservations/{reservation}', [P::class, 'cancel']);
});

Route::middleware(['auth', 'throttle:platform-api'])->group(function () {
    Route::get('/api/simulations', [SimulationController::class, 'index']);
    Route::post('/api/reservations/{reservation}/simulate-start', [SimulationController::class, 'start']);
    Route::put('/api/simulations/{session}/meter', [SimulationController::class, 'meter']);
    Route::post('/api/simulations/{session}/stop', [SimulationController::class, 'stop']);
});

Route::get('/api/admin/overview', function (Request $r) {
    abort_unless($r->user()->role === 'admin', 403);

    return response()->json(['users' => User::count(), 'stations' => Station::count(), 'reservations' => Reservation::count(), 'simulations' => ChargingSession::count(), 'audit_events' => DB::table('audit_events')->orderByDesc('id')->limit(50)->get()]);
})->middleware(['auth', 'throttle:platform-api']);

Route::get('/api/regions', [EnterpriseController::class, 'regions'])->middleware('throttle:catalog');
Route::middleware(['auth', 'throttle:platform-api'])->group(function () {
    $c = EnterpriseController::class;
    Route::put('/api/regions/{country}', [$c, 'configureRegion']);
    Route::get('/api/fleets', [$c, 'index']);
    Route::post('/api/fleets', [$c, 'create']);
    Route::get('/api/fleets/{fleet}', [$c, 'show']);
    Route::post('/api/fleets/{fleet}/invitations', [$c, 'invite']);
    Route::get('/api/fleet-invitations', [$c, 'invitations']);
    Route::post('/api/fleet-invitations/{invitation}/accept', [$c, 'accept']);
    Route::delete('/api/fleets/{fleet}/members/{membership}', [$c, 'remove']);
    Route::post('/api/fleets/{fleet}/cost-centers', [$c, 'costCenter']);
    Route::post('/api/fleets/{fleet}/vehicles', [$c, 'assign']);
});

Route::middleware('throttle:recovery')->group(function () {
    Route::post('/api/forgot-password', [AccountController::class, 'forgot']);
    Route::post('/api/reset-password', [AccountController::class, 'reset']);
});
Route::get('/reset-password/{token}', fn (string $token) => view('reset-password', ['token' => $token]))->name('password.reset');
Route::patch('/api/profile', [AccountController::class, 'profile'])->middleware(['auth', 'throttle:platform-api']);

Route::middleware(['auth', 'throttle:charger-commands'])->group(function () {
    $c = ChargerCommandController::class;
    Route::post('/api/stations/{station}/csms-binding', [$c, 'bind']);
    Route::post('/api/stations/{station}/commands', [$c, 'create']);
    Route::get('/api/charger-commands', [$c, 'index']);
});

Route::middleware(['auth', 'throttle:support'])->group(function () {
    $c = SupportController::class;
    Route::get('/api/support', [$c, 'index']);
    Route::post('/api/support', [$c, 'create']);
    Route::get('/api/support/{ticket}', [$c, 'show']);
    Route::post('/api/support/{ticket}/messages', [$c, 'reply']);
    Route::patch('/api/support/{ticket}/status', [$c, 'transition']);
    Route::get('/api/notifications', [$c, 'notifications']);
    Route::post('/api/notifications/{notification}/read', [$c, 'read']);
});

Route::post('/api/email/verification-notification', [AccountController::class, 'sendVerification'])
    ->middleware(['auth', 'throttle:verification-send']);
Route::get('/email/verify/{id}/{hash}', [AccountController::class, 'verifyEmail'])
    ->middleware(['auth', 'signed', 'throttle:verification-link'])->name('verification.verify');

Route::middleware(['auth', 'throttle:platform-api'])->prefix('api')->group(__DIR__.'/finance.php');
Route::middleware(['auth', 'throttle:platform-api'])->prefix('api/vendor-ops')->group(__DIR__.'/vendor_ops.php');
require __DIR__.'/ocpi.php';

Route::middleware(['auth', 'throttle:platform-api'])->group(function () {
    Route::post('/api/reservations/{reservation}/csms-start', [CsmsSessionController::class, 'start']);
    Route::post('/api/charging-sessions/{session}/csms-stop', [CsmsSessionController::class, 'stop']);
    Route::post('/api/charging-sessions/{session}/reconcile', [CsmsSessionController::class, 'reconcile']);
});

Route::get('/api/stations/{station}/connectors', [ConnectorTopologyController::class, 'options'])->middleware('throttle:catalog');

Route::post('/api/reservations/{reservation}/check-in', [ReservationLifecycleController::class, 'checkIn'])->middleware(['auth', 'throttle:platform-api']);

Route::middleware(['auth', 'throttle:platform-api'])->prefix('api')->group(__DIR__.'/charging_operations.php');
Route::middleware(['auth', 'throttle:platform-api'])->prefix('api')->group(__DIR__.'/rider_access.php');

Route::middleware(['auth', 'throttle:platform-api'])->prefix('api')->group(__DIR__.'/favorites.php');

Route::prefix('api')->group(__DIR__.'/discovery.php');

// A return URL is navigation only, never evidence of payment or authorization.
Route::get('/payments/hosted-return', fn () => response()->view('payments.hosted-return')->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'"));
Route::get('/payments/hosted-simulation/{checkout}', function (string $checkout) {
    abort_unless(app()->environment('local', 'testing') && config('payments.provider') === 'mock', 404);

    return response()->view('payments.hosted-return', ['simulationCheckout' => $checkout])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
})->where('checkout', '[0-9]{1,18}')->middleware('auth');

// Server-selected corporate family; query parameters never choose an API or prove payment.
Route::get('/payments/corporate-hosted-return', fn () => response()->view('payments.hosted-return', ['checkoutFamily' => 'corporate'])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'"));
Route::get('/payments/corporate-hosted-simulation/{checkout}', function (string $checkout) {
    abort_unless(app()->environment('local', 'testing') && config('payments.provider') === 'mock', 404);

    return response()->view('payments.hosted-return', ['checkoutFamily' => 'corporate', 'simulationCheckout' => $checkout])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
})->where('checkout', '[0-9]{1,18}')->middleware('auth');

Route::get('/api/stations/{station}/reviews', [ChargingReviewController::class, 'catalog'])->where('station', '[0-9]{1,18}')->middleware('throttle:catalog');
Route::prefix('/api/charging-reviews')->middleware(['auth', 'throttle:platform-api'])->group(__DIR__.'/charging_reviews.php');

Route::get('/api/stations/{station}/information', [StationInformationController::class, 'show'])->whereNumber('station')->middleware('throttle:catalog');
Route::put('/api/stations/{station}/information', [StationInformationController::class, 'save'])->whereNumber('station')->middleware(['auth', 'throttle:platform-api']);
Route::get('/api/vendor/stations', function (Request $r) {
    abort_unless($r->user()->role === 'vendor', 403);

    return Station::where('vendor_id', $r->user()->id)->orderByDesc('id')->paginate(25);
})->middleware(['auth', 'throttle:platform-api']);

Route::middleware(['auth', 'throttle:platform-api'])->prefix('api')->group(__DIR__.'/vehicle_driver_preferences.php');

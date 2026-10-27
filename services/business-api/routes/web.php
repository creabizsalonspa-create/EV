<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ChargerCommandController;
use App\Http\Controllers\CsmsSessionController;
use App\Http\Controllers\EnterpriseController;
use App\Http\Controllers\PlatformController as P;
use App\Http\Controllers\SimulationController;
use App\Http\Controllers\SupportController;
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
});

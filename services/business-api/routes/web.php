<?php

use App\Http\Controllers\PlatformController as P;
use App\Http\Controllers\SimulationController;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::view('/', 'platform');
Route::get('/api/csrf', fn () => response()->json(['token' => csrf_token()]));
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/api/register', [P::class, 'register']);
    Route::post('/api/login', [P::class, 'login']);
});
Route::get('/api/stations', [P::class, 'stations'])->middleware('throttle:60,1');
Route::middleware(['auth', 'throttle:60,1'])->group(function () {
    Route::get('/api/me', fn (Request $r) => response()->json($r->user()));
    Route::post('/api/logout', [P::class, 'logout']);
    Route::post('/api/stations', [P::class, 'createStation']);
    Route::get('/api/vehicles', [P::class, 'vehicles']);
    Route::post('/api/vehicles', [P::class, 'createVehicle']);
    Route::get('/api/reservations', [P::class, 'reservations']);
    Route::post('/api/reservations', [P::class, 'reserve']);
    Route::delete('/api/reservations/{reservation}', [P::class, 'cancel']);
});

Route::middleware(['auth', 'throttle:60,1'])->group(function () {
    Route::get('/api/simulations', [SimulationController::class, 'index']);
    Route::post('/api/reservations/{reservation}/simulate-start', [SimulationController::class, 'start']);
    Route::put('/api/simulations/{session}/meter', [SimulationController::class, 'meter']);
    Route::post('/api/simulations/{session}/stop', [SimulationController::class, 'stop']);
});

Route::get('/api/admin/overview', function (Request $r) {
    abort_unless($r->user()->role === 'admin', 403);

    return response()->json(['users' => User::count(), 'stations' => Station::count(), 'reservations' => Reservation::count(), 'simulations' => ChargingSession::count(), 'audit_events' => DB::table('audit_events')->orderByDesc('id')->limit(50)->get()]);
})->middleware(['auth', 'throttle:60,1']);

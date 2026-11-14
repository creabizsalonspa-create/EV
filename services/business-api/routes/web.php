<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountPrivacyController;
use App\Http\Controllers\AccountSecondFactorController;
use App\Http\Controllers\AccountSessionController;
use App\Http\Controllers\AdminAccountDirectoryController;
use App\Http\Controllers\AdminAuditTrailController;
use App\Http\Controllers\AdminKycQueueController;
use App\Http\Controllers\AdminOperatorRoleHistoryController;
use App\Http\Controllers\AdminSecondFactorController;
use App\Http\Controllers\AdminSessionController;
use App\Http\Controllers\AdminStaffPermissionController;
use App\Http\Controllers\ChargerCommandController;
use App\Http\Controllers\ChargingReviewController;
use App\Http\Controllers\ConnectorTopologyController;
use App\Http\Controllers\CsmsSessionController;
use App\Http\Controllers\EnterpriseController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\PhoneVerificationController;
use App\Http\Controllers\PlatformController as P;
use App\Http\Controllers\ReservationLifecycleController;
use App\Http\Controllers\SimulationController;
use App\Http\Controllers\StationAvailabilityBlockController;
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

// Status/page refreshes are safe and should not consume password/code
// challenge attempts. Every factor mutation uses the tighter auth limiter.
Route::middleware(['auth', 'throttle:platform-api'])->group(function () {
    Route::get('/admin/second-factor', [AdminSecondFactorController::class, 'page']);
    Route::get('/api/admin/second-factor/status', [AdminSecondFactorController::class, 'status']);
});
Route::middleware(['auth', 'throttle:authentication'])->group(function () {
    Route::post('/api/admin/second-factor/begin', [AdminSecondFactorController::class, 'begin']);
    Route::post('/api/admin/second-factor/confirm', [AdminSecondFactorController::class, 'confirm']);
    Route::post('/api/admin/second-factor/verify', [AdminSecondFactorController::class, 'verify']);
    Route::post('/api/admin/second-factor/recover', [AdminSecondFactorController::class, 'recover']);
    Route::post('/api/admin/second-factor/reset-after-recovery', [AdminSecondFactorController::class, 'resetAfterRecovery']);
    Route::post('/api/admin/second-factor/rotate-recovery-codes', [AdminSecondFactorController::class, 'rotateRecoveryCodes']);
});

Route::get('/api/csrf', fn () => response()->json(['token' => csrf_token()]));
Route::middleware('throttle:authentication')->group(function () {
    Route::post('/api/register', [P::class, 'register']);
    Route::post('/api/login', [P::class, 'login'])->middleware('throttle:signin-account');
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

Route::get('/api/admin/accounts', [AdminAccountDirectoryController::class, 'index'])->middleware(['auth', 'throttle:platform-api']);
Route::post('/api/account/sessions/revoke-all', [AccountSessionController::class, 'revokeAll'])->middleware(['auth', 'throttle:authentication']);
Route::post('/api/admin/sessions/revoke-all', [AdminSessionController::class, 'revokeAll'])->middleware(['auth', 'throttle:authentication']);
Route::get('/api/account/privacy-requests', [AccountPrivacyController::class, 'mine'])->middleware(['auth', 'throttle:platform-api']);
Route::post('/api/account/privacy-requests', [AccountPrivacyController::class, 'submit'])->middleware(['auth', 'throttle:authentication']);
Route::post('/api/account/privacy-requests/{privacyCase}/withdraw', [AccountPrivacyController::class, 'withdraw'])->where('privacyCase', '[0-9]{1,18}')->middleware(['auth', 'throttle:platform-api']);
Route::get('/api/admin/privacy-requests', [AccountPrivacyController::class, 'adminIndex'])->middleware(['auth', 'throttle:platform-api']);
Route::patch('/api/admin/privacy-requests/{privacyCase}/review', [AccountPrivacyController::class, 'review'])->middleware(['auth', 'throttle:authentication']);
Route::get('/api/admin/privacy-requests/{privacyCase}/reviews', [AccountPrivacyController::class, 'adminHistory'])->middleware(['auth', 'throttle:platform-api']);
Route::get('/api/admin/audit-events', [AdminAuditTrailController::class, 'index'])->middleware(['auth', 'throttle:platform-api']);
Route::get('/api/admin/kyc-queue', [AdminKycQueueController::class, 'index'])->middleware(['auth', 'throttle:platform-api']);
Route::get('/api/admin/permission-grants', [AdminStaffPermissionController::class, 'index'])->middleware(['auth', 'throttle:platform-api']);
Route::post('/api/admin/permission-grants/{membership}/revoke', [AdminStaffPermissionController::class, 'revoke'])->middleware(['auth', 'throttle:authentication']);
Route::post('/api/admin/permission-grants/{membership}/release', [AdminStaffPermissionController::class, 'release'])->middleware(['auth', 'throttle:authentication']);
Route::get('/api/admin/permission-grants/{membership}/revocations', [AdminStaffPermissionController::class, 'revocations'])->middleware(['auth', 'throttle:platform-api']);
Route::patch('/api/admin/accounts/{target}/moderate', [AdminAccountDirectoryController::class, 'moderate'])->middleware(['auth', 'throttle:authentication']);
Route::get('/api/admin/accounts/{target}/moderations', [AdminAccountDirectoryController::class, 'moderationHistory'])->middleware(['auth', 'throttle:platform-api']);

Route::get('/api/admin/operator-role-changes', [AdminOperatorRoleHistoryController::class, 'index'])->middleware(['auth', 'throttle:platform-api']);

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
});

// The existing session-backed /api routes and bearer-token /api/v1 routes
// must expose equivalent scoped inbox operations. Inbox reads never exhaust
// the support-ticket action allowance, and writes receive a tighter budget.
Route::middleware(['auth', 'throttle:notification-read'])->group(function () {
    Route::get('/api/notifications', [SupportController::class, 'notifications']);
    Route::get('/api/notifications/summary', [NotificationCenterController::class, 'summary']);
    Route::get('/api/notifications/preferences', [NotificationCenterController::class, 'preferences']);
});
Route::middleware(['auth', 'throttle:notification-write'])->group(function () {
    Route::put('/api/notifications/preferences', [NotificationCenterController::class, 'updatePreferences']);
    Route::post('/api/notifications/read-through', [NotificationCenterController::class, 'readThrough']);
    Route::post('/api/notifications/{notification}/read', [SupportController::class, 'read']);
    Route::post('/api/notifications/{notification}/unread', [NotificationCenterController::class, 'markUnread']);
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

Route::prefix('api')->middleware(['auth', 'throttle:platform-api'])->group(__DIR__.'/vehicle_model_catalog.php');
Route::prefix('api')->middleware(['auth', 'throttle:platform-api'])->group(__DIR__.'/vehicle_manual_soc.php');
Route::get('/api/stations/{station}/information', [StationInformationController::class, 'show'])->where('station', '[0-9]{1,18}')->middleware('throttle:catalog');
Route::put('/api/stations/{station}/information', [StationInformationController::class, 'save'])->where('station', '[0-9]{1,18}')->middleware(['auth', 'throttle:platform-api']);
Route::get('/api/vendor/stations', function (Request $r) {
    abort_unless($r->user()->role === 'vendor', 403);

    return Station::where('vendor_id', $r->user()->id)->orderByDesc('id')->paginate(25);
})->middleware(['auth', 'throttle:platform-api']);

Route::middleware(['auth', 'throttle:platform-api'])->prefix('api')->group(__DIR__.'/vehicle_driver_preferences.php');

Route::get('/api/account/phone', [PhoneVerificationController::class, 'show'])->middleware(['auth', 'throttle:account-security-read']);
Route::middleware(['auth', 'throttle:authentication'])->group(function () {
    Route::post('/api/account/phone/challenges', [PhoneVerificationController::class, 'start']);
    Route::post('/api/account/phone/challenges/{challenge}/confirm', [PhoneVerificationController::class, 'confirm'])->whereUuid('challenge');
    Route::delete('/api/account/phone', [PhoneVerificationController::class, 'remove']);
});

Route::get('/api/account/second-factor', [AccountSecondFactorController::class, 'status'])->middleware(['auth', 'throttle:account-security-read']);
Route::middleware(['auth', 'throttle:authentication'])->group(function () {
    Route::post('/api/account/second-factor/prepare', [AccountSecondFactorController::class, 'prepare']);
    Route::post('/api/account/second-factor/enable', [AccountSecondFactorController::class, 'enable']);
    Route::post('/api/account/second-factor/{action}', [AccountSecondFactorController::class, 'change'])->whereIn('action', ['disable', 'recovery-codes']);
});

Route::get('/api/stations/{station}/availability-blocks', [StationAvailabilityBlockController::class, 'index'])->where('station', '[0-9]{1,18}')->middleware('throttle:catalog');
Route::post('/api/stations/{station}/availability-blocks', [StationAvailabilityBlockController::class, 'store'])->where('station', '[0-9]{1,18}')->middleware(['auth', 'throttle:platform-api']);
Route::post('/api/stations/{station}/availability-blocks/{block}/cancel', [StationAvailabilityBlockController::class, 'cancel'])->where('station', '[0-9]{1,18}')->whereUuid('block')->middleware(['auth', 'throttle:platform-api']);

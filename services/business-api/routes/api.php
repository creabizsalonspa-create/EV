<?php

use App\Http\Controllers\AccountController as A;
use App\Http\Controllers\AccountPrivacyController;
use App\Http\Controllers\AccountSecondFactorController;
use App\Http\Controllers\AccountSessionController;
use App\Http\Controllers\ChargerDiagnosticLogController;
use App\Http\Controllers\ChargerFirmwareController;
use App\Http\Controllers\ChargerIngressController;
use App\Http\Controllers\ChargingReviewController;
use App\Http\Controllers\ConnectorTopologyController;
use App\Http\Controllers\CsmsSessionController;
use App\Http\Controllers\EnterpriseController as E;
use App\Http\Controllers\MobileAuthController as M;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\OcpiProtocolCredentialsController;
use App\Http\Controllers\OcpiProtocolRecordsController;
use App\Http\Controllers\OcpiRoamingProtocolController;
use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\PhoneVerificationController;
use App\Http\Controllers\PlatformController as P;
use App\Http\Controllers\PrechargePricingController;
use App\Http\Controllers\PrivacyAccessExportController;
use App\Http\Controllers\RecurringPaymentController;
use App\Http\Controllers\RemoteSessionAuthorizationController;
use App\Http\Controllers\ReservationLifecycleController;
use App\Http\Controllers\RiderSourceController;
use App\Http\Controllers\RoamingOrderController;
use App\Http\Controllers\SimulationController as S;
use App\Http\Controllers\StationAvailabilityBlockController;
use App\Http\Controllers\StationInformationController;
use App\Http\Controllers\SupportController as T;
use App\Http\Controllers\UnifiedDiscoveryController;
use App\Http\Controllers\VehicleBatteryController;
use App\Http\Middleware\RejectAdminPersonalAccessToken;
use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:authentication')->group(function () {
        Route::post('/login', [M::class, 'login'])->middleware('throttle:signin-account');
        Route::post('/register', [M::class, 'register']);
    });
    Route::post('/account/sessions/revoke-all', [AccountSessionController::class, 'revokeAll'])->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:authentication']);
    Route::get('/account/phone', [PhoneVerificationController::class, 'show'])->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:account-security-read']);
    Route::middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:authentication'])->group(function () {
        Route::post('/account/phone/challenges', [PhoneVerificationController::class, 'start']);
        Route::post('/account/phone/challenges/{challenge}/confirm', [PhoneVerificationController::class, 'confirm'])->whereUuid('challenge');
        Route::delete('/account/phone', [PhoneVerificationController::class, 'remove']);
    });
    Route::get('/account/second-factor', [AccountSecondFactorController::class, 'status'])->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:account-security-read']);
    Route::middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:authentication'])->group(function () {
        Route::post('/account/second-factor/prepare', [AccountSecondFactorController::class, 'prepare']);
        Route::post('/account/second-factor/enable', [AccountSecondFactorController::class, 'enable']);
        Route::post('/account/second-factor/{action}', [AccountSecondFactorController::class, 'change'])->whereIn('action', ['disable', 'recovery-codes']);
    });
    Route::get('/stations', [P::class, 'stations'])->middleware('throttle:catalog');
    Route::get('/discovery/search', [UnifiedDiscoveryController::class, 'index'])->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:catalog']);
    Route::get('/regions', [E::class, 'regions'])->middleware('throttle:catalog');
    Route::middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api'])->group(function () {
        Route::post('/privacy-requests/{privacyCase}/access-copy', [PrivacyAccessExportController::class, 'read'])->where('privacyCase', '[0-9]{1,18}')->middleware('throttle:authentication');
        Route::post('/privacy-requests/{privacyCase}/access-copy/revoke', [PrivacyAccessExportController::class, 'revoke'])->where('privacyCase', '[0-9]{1,18}');
        Route::get('/privacy-requests', [AccountPrivacyController::class, 'mine']);
        Route::post('/privacy-requests', [AccountPrivacyController::class, 'submit']);
        Route::post('/privacy-requests/{privacyCase}/withdraw', [AccountPrivacyController::class, 'withdraw'])->where('privacyCase', '[0-9]{1,18}');
        Route::group([], __DIR__.'/finance.php');
        Route::prefix('vendor-ops')->group(__DIR__.'/vendor_ops.php');
        Route::get('/me', fn (Request $r) => response()->json($r->user()));
        Route::post('/logout', [M::class, 'logout']);
        Route::patch('/profile', [A::class, 'profile']);
        Route::get('/vehicles', [P::class, 'vehicles']);
        Route::post('/vehicles', [P::class, 'createVehicle']);
        Route::get('/vehicles/{vehicle}/battery-preferences', [VehicleBatteryController::class, 'show']);
        Route::put('/vehicles/{vehicle}/battery-preferences', [VehicleBatteryController::class, 'update']);
        Route::post('/vehicles/{vehicle}/battery-simulation', [VehicleBatteryController::class, 'simulate']);
        Route::post('/stations', [P::class, 'createStation']);
        Route::get('/vendor/stations', function (Request $r) {
            abort_unless($r->user()->role === 'vendor', 403);

            return Station::where('vendor_id', $r->user()->id)->orderByDesc('id')->paginate(25);
        });
        Route::get('/reservations', [P::class, 'reservations']);
        Route::post('/reservations', [P::class, 'reserve']);
        Route::post('/reservations/{reservation}/price-estimate', [PrechargePricingController::class, 'preview']);
        Route::post('/reservations/{reservation}/price-consent', [PrechargePricingController::class, 'accept']);
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
    Route::middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:support'])->group(function () {
        Route::get('/support', [T::class, 'index']);
        Route::post('/support', [T::class, 'create']);
        Route::get('/support/{ticket}', [T::class, 'show']);
        Route::post('/support/{ticket}/messages', [T::class, 'reply']);
    });
    // Inbox navigation cannot consume the customer's 30/minute support-action
    // allowance. Writes have a distinct, tighter authenticated limiter.
    Route::middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:notification-read'])->group(function () {
        Route::get('/notifications', [T::class, 'notifications']);
        Route::get('/notifications/summary', [NotificationCenterController::class, 'summary']);
        Route::get('/notifications/preferences', [NotificationCenterController::class, 'preferences']);
    });
    Route::middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:notification-write'])->group(function () {
        Route::put('/notifications/preferences', [NotificationCenterController::class, 'updatePreferences']);
        Route::post('/notifications/read-through', [NotificationCenterController::class, 'readThrough']);
        Route::post('/notifications/{notification}/read', [T::class, 'read']);
        Route::post('/notifications/{notification}/unread', [NotificationCenterController::class, 'markUnread']);
    });
});

Route::post('/internal/csms/events', [CsmsSessionController::class, 'event'])->middleware('throttle:csms-events');

Route::post('/payments/mock/webhook', [PaymentGatewayController::class, 'webhook'])->middleware('throttle:platform-api');

Route::post('/payments/stripe-test/webhook', [PaymentGatewayController::class, 'stripeWebhook'])->middleware('throttle:platform-api');

require __DIR__.'/subscription_webhooks.php';

Route::get('/v1/stations/{station}/connectors', [ConnectorTopologyController::class, 'options'])->middleware('throttle:catalog');

Route::post('/v1/reservations/{reservation}/check-in', [ReservationLifecycleController::class, 'checkIn'])->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api']);

Route::post('/v1/charging-sessions/{session}/reconcile', [CsmsSessionController::class, 'reconcile'])->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api']);

Route::post('/v1/reservations/{reservation}/csms-start', [CsmsSessionController::class, 'start'])->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api']);
Route::post('/v1/charging-sessions/{session}/csms-stop', [CsmsSessionController::class, 'stop'])->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api']);

Route::post('/internal/charger-ingress/authenticate', [ChargerIngressController::class, 'authenticate'])->middleware('throttle:charger-admission');

Route::middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api'])->prefix('v1')->group(__DIR__.'/charging_operations.php');
Route::middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api'])->prefix('v1')->group(__DIR__.'/rider_access.php');
Route::post('/internal/rider-oauth/token', [RiderSourceController::class, 'token'])->middleware('throttle:charger-admission');
Route::post('/internal/rider-authorize', [RiderSourceController::class, 'authorize'])->middleware('throttle:charger-admission');

require __DIR__.'/subscription_credit_webhooks.php';

Route::post('/payment-enrollments/stripe-test/webhook', [RecurringPaymentController::class, 'webhook'])->middleware('throttle:platform-api');

Route::middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api'])->prefix('v1')->group(__DIR__.'/favorites.php');

require __DIR__.'/payout_webhooks.php';
require __DIR__.'/external_dispute_webhooks.php';

Route::prefix('v1')->group(__DIR__.'/discovery.php');

require __DIR__.'/campaign_delivery.php';

require __DIR__.'/corporate_hosted_webhook.php';

Route::post('/internal/remote-session-authorize', RemoteSessionAuthorizationController::class)->middleware('throttle:charger-admission');

Route::get('/internal/firmware/{token}', [ChargerFirmwareController::class, 'download'])->where('token', '[a-f0-9]{64}')->middleware('throttle:30,1');

Route::prefix('roaming-protocol')->middleware('throttle:60,1')->group(function () {
    Route::post('2.2.1/commands/{key}', [OcpiRoamingProtocolController::class, 'callback'])->whereUuid('key');
    Route::get('2.2.1/tokens', [OcpiRoamingProtocolController::class, 'tokens']);
    Route::get('2.2.1/tokens/{country}/{party}/{uid}', [OcpiRoamingProtocolController::class, 'token']);
    Route::post('2.2.1/tokens/{uid}/authorize', [OcpiRoamingProtocolController::class, 'authorize']);
    Route::match(['GET', 'PUT', 'PATCH'], '2.2.1/sessions/{country}/{party}/{external}', [OcpiProtocolRecordsController::class, 'session']);
    Route::post('2.2.1/cdrs', [OcpiProtocolRecordsController::class, 'cdr']);
    Route::get('2.2.1/cdrs/{external}', [OcpiProtocolRecordsController::class, 'cdr']);
    Route::get('versions', [OcpiProtocolCredentialsController::class, 'handle']);
    Route::get('2.2.1', [OcpiProtocolCredentialsController::class, 'handle'])->defaults('resource', 'details');
    Route::match(['GET', 'POST', 'PUT', 'DELETE'], '2.2.1/credentials', [OcpiProtocolCredentialsController::class, 'handle'])->defaults('resource', 'credentials');
});

Route::middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api'])->prefix('v1/roaming')->group(__DIR__.'/roaming_orders.php');
Route::get('v1/ocpi/providers/{provider}/sandbox-orders', [RoamingOrderController::class, 'operations'])->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api']);
// Expiring source-only upload capability; never a browser account or configurable remote URL.
Route::put('/internal/diagnostic-logs/{operation}', [ChargerDiagnosticLogController::class, 'upload'])->where('operation', '[0-9]{1,18}')->middleware('throttle:30,1');

Route::get('/v1/stations/{station}/reviews', [ChargingReviewController::class, 'catalog'])->where('station', '[0-9]{1,18}')->middleware('throttle:catalog');
Route::prefix('/v1/charging-reviews')->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api'])->group(__DIR__.'/charging_reviews.php');
Route::prefix('v1')->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api'])->group(__DIR__.'/vehicle_model_catalog.php');
Route::prefix('v1')->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api'])->group(__DIR__.'/vehicle_manual_soc.php');

// Operator-supplied station access information is not charger-source availability.
Route::get('/v1/stations/{station}/information', [StationInformationController::class, 'show'])->where('station', '[0-9]{1,18}')->middleware('throttle:catalog');
Route::put('/v1/stations/{station}/information', [StationInformationController::class, 'save'])->where('station', '[0-9]{1,18}')->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api']);

Route::middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api'])->prefix('v1')->group(__DIR__.'/vehicle_driver_preferences.php');

Route::get('/v1/stations/{station}/availability-blocks', [StationAvailabilityBlockController::class, 'index'])->where('station', '[0-9]{1,18}')->middleware('throttle:catalog');
Route::post('/v1/stations/{station}/availability-blocks', [StationAvailabilityBlockController::class, 'store'])->where('station', '[0-9]{1,18}')->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api']);
Route::post('/v1/stations/{station}/availability-blocks/{block}/cancel', [StationAvailabilityBlockController::class, 'cancel'])->where('station', '[0-9]{1,18}')->whereUuid('block')->middleware(['auth:sanctum', RejectAdminPersonalAccessToken::class, 'throttle:platform-api']);

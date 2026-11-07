<?php

use App\Http\Controllers\PayoutController as P;
use Illuminate\Support\Facades\Route;

// Mount within existing authenticated web/native API groups.
Route::get('/commercial/payout-accounts', [P::class, 'accounts']);
Route::post('/commercial/vendors/{vendor}/payout-accounts', [P::class, 'configure'])->whereNumber('vendor');
Route::post('/commercial/payout-accounts/{account}/consent', [P::class, 'consent'])->whereNumber('account');
Route::post('/commercial/payout-accounts/{account}/{action}', [P::class, 'accountAction'])->whereNumber('account')->whereIn('action', ['verify', 'revoke']);
Route::post('/commercial/settlements/{settlement}/payouts', [P::class, 'create'])->whereNumber('settlement');
Route::get('/commercial/payouts', [P::class, 'index']);
Route::get('/commercial/payouts/{payout}', [P::class, 'show'])->whereNumber('payout');
Route::post('/commercial/payouts/{payout}/{action}', [P::class, 'action'])->whereNumber('payout')->whereIn('action', ['approve', 'execute', 'reconcile', 'retry', 'cancel']);
Route::post('/commercial/payout-accounts/{account}/onboarding', [P::class, 'onboarding'])->whereNumber('account');

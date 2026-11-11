<?php

use App\Http\Controllers\PayoutController as P;
use Illuminate\Support\Facades\Route;

// Mount within existing authenticated web/native API groups.
Route::get('/commercial/payout-accounts', [P::class, 'accounts']);
Route::post('/commercial/vendors/{vendor}/payout-accounts', [P::class, 'configure'])->where('vendor', '[0-9]{1,18}');
Route::post('/commercial/payout-accounts/{account}/consent', [P::class, 'consent'])->where('account', '[0-9]{1,18}');
Route::post('/commercial/payout-accounts/{account}/{action}', [P::class, 'accountAction'])->where('account', '[0-9]{1,18}')->whereIn('action', ['verify', 'revoke']);
Route::post('/commercial/settlements/{settlement}/payouts', [P::class, 'create'])->where('settlement', '[0-9]{1,18}');
Route::get('/commercial/payouts', [P::class, 'index']);
Route::get('/commercial/payouts/{payout}', [P::class, 'show'])->where('payout', '[0-9]{1,18}');
Route::post('/commercial/payouts/{payout}/{action}', [P::class, 'action'])->where('payout', '[0-9]{1,18}')->whereIn('action', ['approve', 'execute', 'reconcile', 'retry', 'cancel']);
Route::post('/commercial/payout-accounts/{account}/onboarding', [P::class, 'onboarding'])->where('account', '[0-9]{1,18}');

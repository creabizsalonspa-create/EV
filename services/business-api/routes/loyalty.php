<?php

use App\Http\Controllers\LoyaltyController as L;
use Illuminate\Support\Facades\Route;

Route::get('/commercial/loyalty/account', [L::class, 'account']);
Route::get('/commercial/loyalty/ledger', [L::class, 'ledger']);
Route::get('/commercial/loyalty/policies', [L::class, 'policies']);
Route::post('/commercial/loyalty/policies', [L::class, 'publishPolicy']);
Route::get('/commercial/loyalty/rewards', [L::class, 'rewards']);
Route::post('/commercial/loyalty/rewards', [L::class, 'publishReward']);
Route::post('/commercial/loyalty/invoices/{invoice}/earn', [L::class, 'earn'])->whereNumber('invoice');
Route::post('/commercial/loyalty/reconcile', [L::class, 'reconcile']);
Route::post('/commercial/loyalty/rewards/{reward}/redeem', [L::class, 'redeem'])->whereNumber('reward');
Route::get('/commercial/loyalty/redemptions', [L::class, 'redemptions']);
Route::get('/commercial/loyalty/redemptions/{redemption}', [L::class, 'show'])->whereNumber('redemption');
Route::post('/commercial/loyalty/redemptions/{redemption}/{action}', [L::class, 'transition'])->whereNumber('redemption')->whereIn('action', ['fulfill', 'reject']);

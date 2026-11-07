<?php

use App\Http\Controllers\SettlementController as S;
use Illuminate\Support\Facades\Route;

// Mount only inside the parent authenticated API group (web and native).
Route::get('/commercial/settlements', [S::class, 'index']);
Route::get('/commercial/vendors/{vendor}/statement', [S::class, 'statement'])->whereNumber('vendor');
Route::put('/commercial/vendors/{vendor}/schedule', [S::class, 'schedule'])->whereNumber('vendor');
Route::post('/commercial/vendors/{vendor}/settlements', [S::class, 'draft'])->whereNumber('vendor');
Route::post('/commercial/vendors/{vendor}/adjustments', [S::class, 'adjustment'])->whereNumber('vendor');
Route::post('/commercial/adjustments/{adjustment}/approve', [S::class, 'approveAdjustment'])->whereNumber('adjustment');
Route::get('/commercial/settlements/{settlement}', [S::class, 'show'])->whereNumber('settlement');
Route::post('/commercial/settlements/{settlement}/{action}', [S::class, 'transition'])->whereNumber('settlement')->whereIn('action', ['approve', 'reject', 'simulate-payout']);

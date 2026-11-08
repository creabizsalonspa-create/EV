<?php

use App\Http\Controllers\SettlementController as S;
use Illuminate\Support\Facades\Route;

// Mount only inside the parent authenticated API group (web and native).
Route::get('/commercial/settlements', [S::class, 'index']);
Route::get('/commercial/vendors/{vendor}/statement', [S::class, 'statement'])->where('vendor', '[0-9]{1,18}');
Route::put('/commercial/vendors/{vendor}/schedule', [S::class, 'schedule'])->where('vendor', '[0-9]{1,18}');
Route::post('/commercial/vendors/{vendor}/settlements', [S::class, 'draft'])->where('vendor', '[0-9]{1,18}');
Route::post('/commercial/vendors/{vendor}/adjustments', [S::class, 'adjustment'])->where('vendor', '[0-9]{1,18}');
Route::post('/commercial/adjustments/{adjustment}/approve', [S::class, 'approveAdjustment'])->where('adjustment', '[0-9]{1,18}');
Route::get('/commercial/settlements/{settlement}', [S::class, 'show'])->where('settlement', '[0-9]{1,18}');
Route::post('/commercial/settlements/{settlement}/{action}', [S::class, 'transition'])->where('settlement', '[0-9]{1,18}')->whereIn('action', ['approve', 'reject', 'simulate-payout']);

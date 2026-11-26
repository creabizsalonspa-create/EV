<?php

use App\Http\Controllers\PartnerRewardGovernanceController as R;
use App\Http\Controllers\RetailPartnerController as C;
use Illuminate\Support\Facades\Route;

Route::get('/admin/retail-partners', [C::class, 'index']);
Route::post('/admin/retail-partners', [C::class, 'create']);
Route::get('/admin/retail-partners/{partner}', [C::class, 'show'])->where('partner', '[0-9]{1,18}');
Route::post('/admin/retail-partners/{partner}/{action}', [C::class, 'action'])
    ->where('partner', '[0-9]{1,18}')->whereIn('action', ['revise', 'approve', 'reject', 'suspend', 'reinstate', 'terminate']);

Route::get('/admin/retail-partners/{partner}/reward-reviews', [R::class, 'index'])->where('partner', '[0-9]{1,18}');
Route::post('/admin/retail-partners/{partner}/reward-reviews', [R::class, 'propose'])->where('partner', '[0-9]{1,18}');
Route::get('/admin/retail-partners/{partner}/reward-reviews/{review}', [R::class, 'show'])
    ->where(['partner' => '[0-9]{1,18}', 'review' => '[0-9]{1,18}']);
Route::post('/admin/retail-partners/{partner}/reward-reviews/{review}/{decision}', [R::class, 'decide'])
    ->where(['partner' => '[0-9]{1,18}', 'review' => '[0-9]{1,18}'])
    ->whereIn('decision', ['approve', 'reject', 'revoke']);

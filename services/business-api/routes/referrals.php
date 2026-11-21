<?php

use App\Http\Controllers\ReferralController as C;
use Illuminate\Support\Facades\Route;

Route::get('/commercial/referral-programs', [C::class, 'programmes']);
Route::post('/commercial/referral-programs', [C::class, 'publish']);
Route::post('/commercial/referral-programs/{programme}/revoke', [C::class, 'revoke'])->where('programme', '[0-9]{1,18}');
Route::get('/commercial/referrals/account', [C::class, 'account']);
Route::post('/commercial/referrals/codes', [C::class, 'code']);
Route::post('/commercial/referrals/accept', [C::class, 'accept']);
Route::post('/commercial/referrals/qualify', [C::class, 'qualify']);
Route::post('/commercial/referrals/withdraw', [C::class, 'withdraw']);
Route::get('/commercial/referrals/rewards', [C::class, 'rewards']);

Route::post('/commercial/referral-programs/publication-receipt', [C::class, 'publicationReceipt']);

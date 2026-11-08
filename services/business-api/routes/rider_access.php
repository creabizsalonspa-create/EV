<?php

use App\Http\Controllers\RiderAccessController as R;
use Illuminate\Support\Facades\Route;

Route::get('/rider-access/tokens', [R::class, 'index']);
Route::get('/rider-access/stations', [R::class, 'stations']);
Route::post('/rider-access/tokens', [R::class, 'issue']);
Route::delete('/rider-access/tokens/{token}', [R::class, 'revoke']);
Route::patch('/rider-access/tokens/{token}', [R::class, 'renew']);
Route::get('/rider-access/tokens/{token}/grants', [R::class, 'requests']);
Route::post('/rider-access/tokens/{token}/grants', [R::class, 'requestGrant']);
Route::delete('/rider-access/tokens/{token}/grants/{grant}', [R::class, 'withdraw'])->where('grant', '[0-9]{1,18}');
Route::get('/vendor-ops/companies/{company}/rider-grants', [R::class, 'operatorRequests']);
Route::post('/vendor-ops/companies/{company}/rider-grants/{grant}/review', [R::class, 'review'])->where('grant', '[0-9]{1,18}');
Route::post('/vendor-ops/companies/{company}/rider-tokens/{token}/provision', [R::class, 'provision'])->where('token', '[0-9]{1,18}');

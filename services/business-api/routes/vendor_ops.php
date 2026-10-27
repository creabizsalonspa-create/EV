<?php

use App\Http\Controllers\VendorOpsController as V;
use Illuminate\Support\Facades\Route;

Route::get('/companies', [V::class, 'companies']);
Route::post('/companies', [V::class, 'createCompany']);
Route::get('/companies/{company}', [V::class, 'company']);
Route::get('/companies/{company}/documents', [V::class, 'documents']);
Route::post('/companies/{company}/documents', [V::class, 'upload']);
Route::get('/companies/{company}/documents/{document}', [V::class, 'download']);
Route::post('/companies/{company}/submit', [V::class, 'submit']);
Route::post('/companies/{company}/review', [V::class, 'review']);
Route::get('/companies/{company}/staff', [V::class, 'staff']);
Route::post('/companies/{company}/staff', [V::class, 'invite']);
Route::get('/staff-invitations', [V::class, 'invitations']);
Route::post('/staff-invitations/{membership}/accept', [V::class, 'accept']);
Route::delete('/companies/{company}/staff/{membership}', [V::class, 'revoke']);
Route::get('/companies/{company}/sites', [V::class, 'sites']);
Route::post('/companies/{company}/sites', [V::class, 'createSite']);
Route::get('/companies/{company}/sites/{site}/chargers', [V::class, 'chargers']);
Route::post('/companies/{company}/sites/{site}/chargers', [V::class, 'createCharger']);
Route::get('/companies/{company}/chargers/{charger}/connectors', [V::class, 'connectors']);
Route::post('/companies/{company}/chargers/{charger}/connectors', [V::class, 'createConnector']);
Route::patch('/companies/{company}/chargers/{charger}/connectors/{connector}/maintenance', [V::class, 'maintenance']);

Route::patch('/companies/{company}', [V::class, 'updateCompany']);
Route::get('/companies/{company}/reviews', [V::class, 'reviews']);

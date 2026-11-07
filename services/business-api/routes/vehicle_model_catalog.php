<?php

use App\Http\Controllers\VehicleModelCatalogController as C;
use Illuminate\Support\Facades\Route;

Route::get('/vehicle-models', [C::class, 'index']);
Route::post('/vehicle-models', [C::class, 'create']);
Route::put('/vehicle-models/{model}', [C::class, 'update'])->whereNumber('model');
Route::get('/vehicles/{vehicle}/model', [C::class, 'vehicle'])->whereNumber('vehicle');
Route::put('/vehicles/{vehicle}/model', [C::class, 'associate'])->whereNumber('vehicle');

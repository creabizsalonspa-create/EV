<?php

use App\Http\Controllers\VehicleModelCatalogController as C;
use Illuminate\Support\Facades\Route;

Route::get('/vehicle-models', [C::class, 'index']);
Route::post('/vehicle-models', [C::class, 'create']);
Route::put('/vehicle-models/{model}', [C::class, 'update'])->where('model', '[0-9]{1,18}');
Route::get('/vehicles/{vehicle}/model', [C::class, 'vehicle'])->where('vehicle', '[0-9]{1,18}');
Route::put('/vehicles/{vehicle}/model', [C::class, 'associate'])->where('vehicle', '[0-9]{1,18}');

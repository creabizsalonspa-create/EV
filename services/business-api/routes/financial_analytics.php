<?php

use App\Http\Controllers\FinancialAnalyticsController as A;
use Illuminate\Support\Facades\Route;

Route::get('/financial/analytics', [A::class, 'show']);
Route::get('/financial/analytics/export', [A::class, 'export']);

Route::get('/financial/analytics/pdf', [A::class, 'pdf']);

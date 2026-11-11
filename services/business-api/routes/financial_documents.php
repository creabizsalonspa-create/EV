<?php

use App\Http\Controllers\FinancialDocumentsController as D;
use Illuminate\Support\Facades\Route;

// Mount inside the existing authenticated/throttled cookie and token API groups.
Route::get('/financial/profiles', [D::class, 'profiles']);
Route::post('/financial/profiles', [D::class, 'publish']);
Route::get('/financial/documents', [D::class, 'index']);
Route::post('/financial/documents', [D::class, 'generate']);
Route::get('/financial/documents/{document}', [D::class, 'show'])->where('document', '[0-9]{1,18}');
Route::get('/financial/documents/{document}/download/{format}', [D::class, 'download'])->where('document', '[0-9]{1,18}')->where('format', 'pdf|csv');

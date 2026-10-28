<?php

use App\Http\Controllers\FinancialDocumentsController as D;
use Illuminate\Support\Facades\Route;

// Mount inside the existing authenticated/throttled cookie and token API groups.
Route::get('/financial/profiles', [D::class, 'profiles']);
Route::post('/financial/profiles', [D::class, 'publish']);
Route::get('/financial/documents', [D::class, 'index']);
Route::post('/financial/documents', [D::class, 'generate']);
Route::get('/financial/documents/{document}', [D::class, 'show'])->whereNumber('document');
Route::get('/financial/documents/{document}/download/{format}', [D::class, 'download'])->whereNumber('document')->where('format', 'pdf|csv');

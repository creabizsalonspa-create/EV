<?php

use App\Http\Controllers\VendorFeedbackAnalyticsController;
use Illuminate\Support\Facades\Route;

Route::get('/vendor/feedback-analytics', [VendorFeedbackAnalyticsController::class, 'index']);

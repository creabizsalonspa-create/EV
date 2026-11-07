<?php

namespace App\Http\Controllers;

use App\Services\Commercial\Analytics\AnalyticsPresentation;
use App\Services\Commercial\Analytics\FinancialAnalytics;
use Illuminate\Http\Request;

class FinancialAnalyticsController extends Controller
{
    public function show(Request $r, FinancialAnalytics $analytics)
    {
        $v = $r->validate(['from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d', 'currency' => ['sometimes', 'regex:/^[A-Z]{3}$/D'], 'user_id' => 'prohibited', 'vendor_id' => 'prohibited']);
        $report = $analytics->report($r->user(), $v);

        return response()->json($report)->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $r, FinancialAnalytics $analytics)
    {
        $v = $r->validate(['from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d', 'currency' => ['sometimes', 'regex:/^[A-Z]{3}$/D'], 'user_id' => 'prohibited', 'vendor_id' => 'prohibited']);
        $report = $analytics->report($r->user(), $v, 'export');

        return response($analytics->csv($report))->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="financial-analytics.csv"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function pdf(Request $r, FinancialAnalytics $analytics, AnalyticsPresentation $presentation)
    {
        $v = $r->validate(['from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d', 'currency' => ['sometimes', 'regex:/^[A-Z]{3}$/D'], 'locale' => 'sometimes|in:en,ar', 'user_id' => 'prohibited', 'vendor_id' => 'prohibited']);
        $report = $analytics->report($r->user(), $v, 'pdf');

        return response($presentation->pdf($report, $v['locale'] ?? 'en'))->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="financial-analytics.pdf"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}

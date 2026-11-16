<?php

namespace App\Http\Controllers;

use App\Security\CurrentFinanceReader;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\OperationalAnalytics;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationalAnalyticsController extends Controller
{
    private function report(Request $r, VendorCompany $company): array
    {
        // A cached account or stale route-bound company can no longer expose
        // another owner's operations or private invoice aggregates. The
        // caller holds this account and company lock until report completion.
        $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
        $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
        $access = app(VendorAccess::class);
        $access->require($actor, $company, 'inventory.read');
        $v = $r->validate(['from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d', 'site_id' => 'sometimes|integer|min:1', 'locale' => 'sometimes|in:en,ar', 'vendor_id' => 'prohibited', 'user_id' => 'prohibited']);
        $financial = $actor->role === 'admin' || $actor->id === $company->owner_id;
        $report = app(OperationalAnalytics::class)->report($company, $v, $financial);
        // Recheck the live staff grant before the response is released.
        // Account and company identities remain locked within the transaction.
        $access->require($actor, $company, 'inventory.read');
        abort_if($financial && $actor->role !== 'admin' && $actor->id !== $company->owner_id, 404);

        return $report + ['financial_visible' => $financial];
    }

    public function show(Request $r, VendorCompany $company)
    {
        return DB::transaction(fn () => response()->json($this->report($r, $company))
            ->header('Cache-Control', 'private, no-store'), 3);
    }

    public function export(Request $r, VendorCompany $company)
    {
        $report = DB::transaction(fn () => $this->report($r, $company), 3);

        return response(app(OperationalAnalytics::class)->csv($report))->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="charging-operations.csv"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function pdf(Request $r, VendorCompany $company)
    {
        $report = DB::transaction(fn () => $this->report($r, $company), 3);
        abort_if(count($report['sites']) > 100, 409, 'Select a site for a smaller PDF.');
        if (! defined('K_TCPDF_EXTERNAL_CONFIG')) {
            define('K_TCPDF_EXTERNAL_CONFIG', true);
        }
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', resource_path('financial-fonts').'/');
        }
        if (! defined('PDF_FONT_NAME_MAIN')) {
            define('PDF_FONT_NAME_MAIN', 'dejavusans');
        }
        $ar = $r->input('locale', 'en') === 'ar';
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(14, 14, 14);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->setFontSubsetting(true);
        $pdf->setRTL($ar);
        $pdf->AddPage();
        $pdf->writeHTML(view('charging.operational-analytics', compact('report', 'ar'))->render(), true, false, true, false, $ar ? 'R' : 'L');

        return response($pdf->Output('charging-operations.pdf', 'S'))->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="charging-operations.pdf"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}

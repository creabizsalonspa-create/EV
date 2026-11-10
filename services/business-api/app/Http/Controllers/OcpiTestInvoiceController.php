<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Ocpi\TestInvoiceLineage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OcpiTestInvoiceController extends Controller
{
    private function order(Request $r, int $order): object
    {
        return DB::table('ocpi_roaming_orders')->where('id', $order)->where('user_id', $r->user()->id)->firstOrFail();
    }

    public function preview(Request $r, int $order, TestInvoiceLineage $service)
    {
        return response()->json($service->preview(User::findOrFail($r->user()->id), $this->order($r, $order)))->header('Cache-Control', 'private, no-store');
    }

    public function issue(Request $r, int $order, TestInvoiceLineage $service, FinanceService $finance)
    {
        $v = $this->consent($r);

        return response()->json($finance->summary($service->issue(User::findOrFail($r->user()->id), $this->order($r, $order), $v)))->header('Cache-Control', 'private, no-store');
    }

    private function consent(Request $r): array
    {
        $v = $r->validate(['request_key' => 'required|uuid', 'preview_token' => 'required|string|max:2048', 'terms_version' => 'required|in:'.TestInvoiceLineage::TERMS, 'consent' => 'required|accepted', 'expected_total_minor' => 'required|integer|between:1,2000000000', 'expected_currency' => 'required|string|regex:/^[A-Z]{3}$/', 'expected_minor_units' => 'required|integer|between:0,3', 'expected_energy_wh' => 'required|integer|between:1,1000000']);
        $v['consent'] = true;
        foreach (['expected_total_minor', 'expected_minor_units', 'expected_energy_wh'] as $key) {
            $v[$key] = (int) $v[$key];
        }

        return $v;
    }

    public function resolve(Request $r, int $order, TestInvoiceLineage $service)
    {
        $v = $r->validate(['request_key' => 'required|uuid', 'cancel_unissued' => 'required|accepted']);

        return response()->json($service->resolve(User::findOrFail($r->user()->id), $this->order($r, $order), $v))->header('Cache-Control', 'private, no-store');
    }
}

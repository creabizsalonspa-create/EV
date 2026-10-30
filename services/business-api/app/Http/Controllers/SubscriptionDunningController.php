<?php

namespace App\Http\Controllers;

use App\Services\Commercial\SubscriptionDunning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionDunningController extends Controller
{
    public function index(Request $request)
    {
        $rows = DB::table('subscription_dunning_notices as n')->join('commercial_subscription_invoices as i', 'i.id', '=', 'n.invoice_id')->join('commercial_subscriptions as s', 's.id', '=', 'i.subscription_id')
            ->when($request->user()->role !== 'admin', fn ($q) => $q->where('s.user_id', $request->user()->id))
            ->select(['n.id', 'n.invoice_id', 'n.stage', 'n.due_at', 'n.created_at', 'i.subscription_id', 'i.number', 'i.currency', 'i.minor_units', 'i.total_minor', 's.status as subscription_status'])
            ->orderByDesc('n.id')->paginate(25)->through(function ($row) {
                $paid = DB::table('commercial_subscription_receipts')->where('invoice_id', $row->invoice_id)->exists();
                $row->attention = $paid ? 'settled' : ($row->subscription_status === 'cancelled' ? 'cancelled' : (SubscriptionDunning::held($row->invoice_id) ? 'recovery_required' : 'payment_due'));
                $row->collection_attempted = false;
                $row->delivery = 'IN_APP_ONLY';

                return $row;
            });

        return response()->json($rows)->header('Cache-Control', 'no-store');
    }
}

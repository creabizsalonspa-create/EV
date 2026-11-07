<?php

namespace App\Http\Controllers;

use App\Services\Commercial\Payments\PaymentRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentRecoveryController extends Controller
{
    private function accessible(Request $r)
    {
        $user = $r->user();

        return DB::table('payment_recovery_intents as p')->where(function ($q) use ($user) {
            if ($user->role === 'admin') {
                return;
            }
            $q->where(function ($q) use ($user) {
                $q->where('p.scope', 'charging')->whereExists(function ($x) use ($user) {
                    $x->selectRaw('1')->from('gateway_checkouts as c')->join('finance_invoices as i', 'i.id', '=', 'c.invoice_id')->whereColumn('c.id', 'p.checkout_id')->where($user->role === 'vendor' ? 'i.vendor_id' : 'i.user_id', $user->id);
                });
            })
                ->orWhere(function ($q) use ($user) {
                    $q->where('p.scope', 'subscription')->whereExists(function ($x) use ($user) {
                        $x->selectRaw('1')->from('subscription_gateway_checkouts as s')->whereColumn('s.id', 'p.checkout_id')->where('s.user_id', $user->id);
                    });
                });
        });
    }

    public function index(Request $r)
    {
        return $this->accessible($r)->orderByDesc('p.id')->paginate(25)->through(fn ($row) => $this->publicRow($row));
    }

    private function publicRow(object $row): array
    {
        $data = (array) $row;
        unset($data['identity'],$data['fingerprint'],$data['lease'],$data['observation']);

        $data['minor_units'] = $row->scope === 'charging' ? DB::table('gateway_checkouts as c')->join('finance_invoices as i', 'i.id', '=', 'c.invoice_id')->where('c.id', $row->checkout_id)->value('i.minor_units') : DB::table('subscription_gateway_checkouts as c')->join('commercial_subscription_invoices as i', 'i.id', '=', 'c.invoice_id')->where('c.id', $row->checkout_id)->value('i.minor_units');

        return $data + ['evidence' => 'STRIPE_TEST_CONTRACT', 'external_provider_verified' => false];
    }

    public function reconcile(Request $r, int $intent, PaymentRecovery $recovery)
    {
        $row = $this->accessible($r)->where('p.id', $intent)->first();
        abort_unless($row, 404);

        return response()->json($this->publicRow($recovery->reconcileProvider($intent)));
    }

    public function events(Request $r, int $intent)
    {
        abort_unless($this->accessible($r)->where('p.id', $intent)->exists(), 404);

        return DB::table('payment_recovery_events')->where('intent_id', $intent)->orderBy('id')->paginate(50);
    }
}

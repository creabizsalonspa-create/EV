<?php

namespace App\Http\Controllers;

use App\Services\Commercial\Payouts\PayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayoutController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->user()->role === 'admin', 403);
    }

    private function scope(Request $r, int $vendor): void
    {
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $r->user()->id === $vendor), 404);
    }

    private function safe(object $row): array
    {
        $v = (array) $row;
        unset($v['request_key']);

        return $v + ['real_money_moved' => false];
    }

    public function accounts(Request $r, PayoutService $service)
    {
        abort_unless(in_array($r->user()->role, ['vendor', 'admin'], true), 403);

        return DB::table('commercial_payout_accounts')->when($r->user()->role !== 'admin', fn ($q) => $q->where('vendor_id', $r->user()->id))->orderByDesc('id')->paginate(25)->through(fn ($row) => $service->publicAccount($row));
    }

    public function configure(Request $r, int $vendor, PayoutService $service)
    {
        $this->admin($r);
        $v = $r->validate(['provider' => 'required|in:mock,stripe_test', 'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'], 'account_reference' => ['required', 'string', 'max:100', 'regex:/^(acct_|mock_)[A-Za-z0-9]+$/D'], 'bank_reference' => ['required', 'string', 'max:100', 'regex:/^(ba_|mock_)[A-Za-z0-9]+$/D'], 'idempotency_key' => 'required|string|max:100']);
        abort_unless($v['provider'] === 'mock' ? str_starts_with($v['account_reference'], 'mock_') && str_starts_with($v['bank_reference'], 'mock_') : str_starts_with($v['account_reference'], 'acct_') && str_starts_with($v['bank_reference'], 'ba_'), 422);

        return response()->json(['account' => $service->publicAccount($service->configure($vendor, $r->user()->id, $v))], 201);
    }

    public function consent(Request $r, int $account, PayoutService $service)
    {
        $row = $service->account($account);
        $this->scope($r, (int) $row->vendor_id);
        $v = $r->validate(['consent' => 'required|accepted', 'consent_version' => 'required|string|max:50']);

        return response()->json(['account' => $service->publicAccount($service->consent($account, $r->user()->id, $v['consent_version']))]);
    }

    public function accountAction(Request $r, int $account, string $action, PayoutService $service)
    {
        $row = $service->account($account);
        $this->scope($r, (int) $row->vendor_id);
        if ($action === 'verify') {
            $this->admin($r);
        }

        return response()->json(['account' => $service->publicAccount($action === 'verify' ? $service->verify($account, $r->user()->id) : $service->revoke($account, $r->user()->id))]);
    }

    public function onboarding(Request $r, int $account, PayoutService $service)
    {
        $v = $r->validate(['idempotency_key' => 'required|string|max:100']);

        return response()->json($service->onboarding($account, $r->user()->id, $v['idempotency_key']))->header('Cache-Control', 'no-store, private');
    }

    public function create(Request $r, int $settlement, PayoutService $service)
    {
        $this->admin($r);
        $v = $r->validate(['account_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|max:100']);

        return response()->json(['payout' => $this->safe($service->create($settlement, (int) $v['account_id'], $r->user()->id, $v['idempotency_key']))], 201);
    }

    public function index(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['vendor', 'admin'], true), 403);

        return DB::table('commercial_payouts')->when($r->user()->role !== 'admin', fn ($q) => $q->where('vendor_id', $r->user()->id))->orderByDesc('id')->paginate(25)->through(fn ($row) => $this->safe($row));
    }

    public function show(Request $r, int $payout, PayoutService $service)
    {
        $row = DB::table('commercial_payouts')->find($payout);
        abort_unless($row, 404);
        $this->scope($r, (int) $row->vendor_id);
        $attempts = DB::table('commercial_payout_attempts')->where('payout_id', $payout)->orderBy('number')->get()->map(function ($a) {
            $v = (array) $a;
            unset($v['lease']);

            return $v;
        });

        return response()->json(['payout' => $this->safe($row), 'account' => $service->publicAccount(DB::table('commercial_payout_accounts')->find($row->account_id)), 'attempts' => $attempts, 'events' => DB::table('commercial_payout_events')->where('payout_id', $payout)->orderBy('id')->get(), 'real_money_moved' => false]);
    }

    public function action(Request $r, int $payout, string $action, PayoutService $service)
    {
        $this->admin($r);

        return response()->json(['payout' => $this->safe($service->{$action}($payout, $r->user()->id))]);
    }
}

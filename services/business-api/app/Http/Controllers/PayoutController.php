<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Security\CurrentFinanceReader;
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

    /**
     * Confirm the current, persisted identity before entering any test payout
     * service. The read account lock is short-lived for methods that can
     * perform external provider I/O; the provider service retains its own
     * payout, bank account, idempotency and maker-checker transactions.
     */
    private function currentActor(Request $r, bool $admin = false): User
    {
        return DB::transaction(function () use ($r, $admin) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            if ($admin) {
                abort_unless($actor->role === 'admin', 403);
            }

            return $actor;
        }, 3);
    }

    private function safe(object $row): array
    {
        $v = (array) $row;
        unset($v['request_key']);

        return $v + ['real_money_moved' => false];
    }

    public function accounts(Request $r, PayoutService $service)
    {
        return DB::transaction(function () use ($r, $service) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless(in_array($actor->role, ['vendor', 'admin'], true), 403);

            return response()->json(DB::table('commercial_payout_accounts')
                ->when($actor->role !== 'admin', fn ($q) => $q->where('vendor_id', $actor->id))
                ->orderByDesc('id')->paginate(25)
                ->through(fn ($row) => $service->publicAccount($row)))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function configure(Request $r, int $vendor, PayoutService $service)
    {
        $this->admin($r);
        $actor = $this->currentActor($r, true);
        $v = $r->validate(['provider' => 'required|in:mock,stripe_test', 'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'], 'account_reference' => ['required', 'string', 'max:100', 'regex:/^(acct_|mock_)[A-Za-z0-9]+$/D'], 'bank_reference' => ['required', 'string', 'max:100', 'regex:/^(ba_|mock_)[A-Za-z0-9]+$/D'], 'idempotency_key' => 'required|string|max:100']);
        abort_unless($v['provider'] === 'mock' ? str_starts_with($v['account_reference'], 'mock_') && str_starts_with($v['bank_reference'], 'mock_') : str_starts_with($v['account_reference'], 'acct_') && str_starts_with($v['bank_reference'], 'ba_'), 422);

        return response()->json(['account' => $service->publicAccount($service->configure($vendor, $actor->id, $v))], 201);
    }

    public function consent(Request $r, int $account, PayoutService $service)
    {
        $actor = $this->currentActor($r);
        $row = $service->account($account);
        abort_unless($actor->role === 'admin'
            || ($actor->role === 'vendor' && $actor->id === (int) $row->vendor_id), 404);
        $v = $r->validate(['consent' => 'required|accepted', 'consent_version' => 'required|string|max:50']);

        return response()->json(['account' => $service->publicAccount($service->consent($account, $actor->id, $v['consent_version']))]);
    }

    public function accountAction(Request $r, int $account, string $action, PayoutService $service)
    {
        $actor = $this->currentActor($r);
        $row = $service->account($account);
        abort_unless($actor->role === 'admin'
            || ($actor->role === 'vendor' && $actor->id === (int) $row->vendor_id), 404);
        if ($action === 'verify') {
            abort_unless($actor->role === 'admin', 403);
        }

        return response()->json(['account' => $service->publicAccount($action === 'verify' ? $service->verify($account, $actor->id) : $service->revoke($account, $actor->id))]);
    }

    public function onboarding(Request $r, int $account, PayoutService $service)
    {
        $actor = $this->currentActor($r);
        abort_unless($actor->role === 'vendor', 404);
        $v = $r->validate(['idempotency_key' => 'required|string|max:100']);

        return response()->json($service->onboarding($account, $actor->id, $v['idempotency_key']))->header('Cache-Control', 'no-store, private');
    }

    public function create(Request $r, int $settlement, PayoutService $service)
    {
        $this->admin($r);
        $actor = $this->currentActor($r, true);
        $v = $r->validate(['account_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|max:100']);

        return response()->json(['payout' => $this->safe($service->create($settlement, (int) $v['account_id'], $actor->id, $v['idempotency_key']))], 201);
    }

    public function index(Request $r)
    {
        return DB::transaction(function () use ($r) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless(in_array($actor->role, ['vendor', 'admin'], true), 403);

            return response()->json(DB::table('commercial_payouts')
                ->when($actor->role !== 'admin', fn ($q) => $q->where('vendor_id', $actor->id))
                ->orderByDesc('id')->paginate(25)->through(fn ($row) => $this->safe($row)))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function show(Request $r, int $payout, PayoutService $service)
    {
        return DB::transaction(function () use ($r, $payout, $service) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $row = DB::table('commercial_payouts')->find($payout);
            abort_unless($row, 404);
            abort_unless($actor->role === 'admin'
                || ($actor->role === 'vendor' && $actor->id === (int) $row->vendor_id), 404);
            $attempts = DB::table('commercial_payout_attempts')
                ->where('payout_id', $payout)->orderBy('number')->get()->map(function ($a) {
                    $v = (array) $a;
                    unset($v['lease']);

                    return $v;
                });

            return response()->json([
                'payout' => $this->safe($row),
                'account' => $service->publicAccount(DB::table('commercial_payout_accounts')
                    ->find($row->account_id)),
                'attempts' => $attempts,
                'events' => DB::table('commercial_payout_events')
                    ->where('payout_id', $payout)->orderBy('id')->get(),
                'real_money_moved' => false,
            ])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function action(Request $r, int $payout, string $action, PayoutService $service)
    {
        $this->admin($r);
        $actor = $this->currentActor($r, true);

        // Do not wrap externally connected execute/reconcile in a transaction:
        // PayoutService manages its own lease and uncertainty recovery gates.
        return response()->json(['payout' => $this->safe($service->{$action}($payout, $actor->id))]);
    }
}

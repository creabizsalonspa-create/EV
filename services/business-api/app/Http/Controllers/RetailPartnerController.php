<?php

namespace App\Http\Controllers;

use App\Security\AdminTransactionAuthority;
use App\Services\Retail\PartnerRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class RetailPartnerController extends Controller
{
    public function page(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);

        return response()->view('retail-partners')->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $r, PartnerRegistry $registry)
    {
        return DB::transaction(function () use ($r, $registry) {
            AdminTransactionAuthority::requireCurrent($r->user());
            $v = $r->validate(['page' => 'sometimes|integer|between:1,100000', 'search' => 'sometimes|nullable|string|max:80']);
            $rows = DB::table('retail_partners')->when(isset($v['search']), fn ($q) => $q->where('reference', 'like', '%'.addcslashes($v['search'], '%_\\').'%'))
                ->orderByDesc('id')->paginate(25)->through(fn ($row) => $registry->read((int) $row->id));

            return response()->json($rows)->header('Cache-Control', 'no-store, private');
        });
    }

    public function show(Request $r, int $partner, PartnerRegistry $registry)
    {
        abort_unless($partner > 0 && $partner <= 9007199254740991, 422);

        return DB::transaction(function () use ($r, $partner, $registry) {
            AdminTransactionAuthority::requireCurrent($r->user());
            $r->validate(['page' => 'sometimes|integer|between:1,100000']);
            $record = $registry->read($partner);
            $events = DB::table('retail_partner_events')->where('partner_id', $partner)->orderByDesc('revision')
                ->select(['id', 'version_id', 'action', 'reason', 'actor_id', 'revision', 'created_at'])->paginate(25);
            $versions = DB::table('retail_partner_versions')->where('partner_id', $partner)
                ->whereIn('id', $events->getCollection()->pluck('version_id'))->get()->keyBy('id');
            $events->through(fn ($event) => (array) $event + ['version_snapshot' => $versions->get($event->version_id)]);

            return response()->json(['partner' => $record, 'events' => $events])->header('Cache-Control', 'no-store, private');
        });
    }

    public function create(Request $r, PartnerRegistry $registry)
    {
        return $this->change($r, null, 'create', $registry);
    }

    public function action(Request $r, int $partner, string $action, PartnerRegistry $registry)
    {
        abort_unless($partner > 0 && $partner <= 9007199254740991, 422);

        return $this->change($r, $partner, $action, $registry);
    }

    private function change(Request $r, ?int $partner, string $action, PartnerRegistry $registry)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $rules = ['reason' => 'required|string|min:5|max:500', 'idempotency_key' => 'required|uuid'];
        if ($partner !== null) {
            $rules['expected_revision'] = 'required|integer|between:1,1000000000';
        }
        if (in_array($action, ['create', 'revise'], true)) {
            $rules += ['legal_name' => 'required|string|max:200', 'display_name' => 'required|string|max:150',
                'display_name_ar' => 'required|string|max:150', 'country' => ['required', Rule::exists('regions', 'country')],
                'category' => 'required|in:retail,hospitality,food,automotive,services', 'website' => 'nullable|url:https|max:500',
                'terms' => 'required|string|min:20|max:10000', 'effective_from' => 'required|date_format:Y-m-d\\TH:i:sP',
                'expires_at' => 'required|date_format:Y-m-d\\TH:i:sP|after:effective_from'];
            if ($partner === null) {
                $rules['reference'] = 'required|string|regex:/^[A-Z0-9][A-Z0-9_-]{2,79}$/D';
            }
        }
        $v = $r->validate($rules);
        // Stable field order makes request identity independent of JSON key order.
        ksort($v);

        return response()->json(['partner' => $registry->mutate($r->user(), $partner, $action, $v)], $partner === null ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }
}

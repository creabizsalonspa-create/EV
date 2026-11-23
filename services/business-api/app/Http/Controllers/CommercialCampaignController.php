<?php

namespace App\Http\Controllers;

use App\Security\CurrentFinanceReader;
use App\Services\Commercial\Campaigns\CampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CommercialCampaignController extends Controller
{
    /** Call only inside a transaction that holds the current account lock. */
    private function scope(Request $r, int $revokedStatus = 409)
    {
        try {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
        } catch (HttpException $error) {
            // Campaign control must preserve its existing non-enumerating
            // 404 for a formerly privileged reviewer, rather than exposing
            // the private campaign's existence via a 409 response.
            if ($revokedStatus !== 409 && $error->getStatusCode() === 409) {
                abort($revokedStatus);
            }

            throw $error;
        }
        abort_unless(in_array($actor->role, ['vendor', 'admin'], true), 403);

        return DB::table('commercial_campaigns')
            ->when($actor->role !== 'admin', fn ($q) => $q->where('vendor_id', $actor->id));
    }

    private function safe(object $row, CampaignService $service): array
    {
        $data = (array) $row;
        unset($data['request_key'], $data['fingerprint']);

        return $data + ['control_revision' => (string) $service->controlRevision($row->id), 'delivered_units' => $service->delivered($row->id), 'verified_server_clicks' => DB::table('commercial_campaign_deliveries')->where('campaign_id', $row->id)->where('kind', 'click')->count(), 'unit' => 'PREPAID_PACKAGE_DELIVERY_UNIT', 'additional_cash_charge' => false, 'human_impressions_verified' => false, 'sponsored' => true];
    }

    public function index(Request $r, CampaignService $s)
    {
        return DB::transaction(fn () => response()->json(
            $this->scope($r)->orderByDesc('id')->paginate(25)
                ->through(fn ($row) => $this->safe($row, $s))->toArray()
                    + ['adapter_enabled' => (bool) config('campaigns.enabled')]
        )->header('Cache-Control', 'private, no-store'), 3);
    }

    public function stations(Request $r)
    {
        return DB::transaction(function () use ($r) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($actor->role === 'vendor', 403);

            return response()->json(DB::table('stations')->where('vendor_id', $actor->id)
                ->orderBy('id')->select('id', 'name', 'country')->paginate(25))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function packages(Request $r, CampaignService $s)
    {
        return DB::transaction(function () use ($r, $s) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless(in_array($actor->role, ['vendor', 'admin'], true), 403);
            $q = DB::table('commercial_subscription_invoices as i')
                ->join('commercial_subscriptions as s', 's.id', '=', 'i.subscription_id')
                ->join('commercial_plan_versions as p', 'p.id', '=', 'i.plan_id')
                ->where('p.audience', 'vendor')
                ->when($actor->role !== 'admin', fn ($q) => $q->where('s.user_id', $actor->id))
                ->select('i.*', 's.user_id');

            return response()->json($q->orderByDesc('i.id')->paginate(25)
                ->through(function ($i) use ($s) {
                    $eligible = true;
                    try {
                        $s->billing($i->id, $i->user_id);
                    } catch (HttpException $error) {
                        $eligible = false;
                    }

                    return [
                        'invoice_id' => $i->id, 'vendor_id' => $i->user_id,
                        'number' => $i->number, 'currency' => $i->currency,
                        'minor_units' => $i->minor_units, 'total_minor' => $i->total_minor,
                        'period_start' => $i->period_start, 'period_end' => $i->period_end,
                        'campaign_eligible' => $eligible,
                        'entitlements' => json_decode($i->entitlements, true),
                        'evidence' => DB::table('commercial_subscription_receipts')
                            ->where('invoice_id', $i->id)->value('evidence') ?? 'UNPAID',
                    ];
                }))->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function create(Request $r, CampaignService $s)
    {
        $v = $r->validate(['invoice_id' => 'required|integer|min:1', 'station_id' => 'required|integer|min:1', 'title' => 'required|string|max:120', 'title_ar' => 'required|string|max:120', 'description' => 'required|string|max:500', 'description_ar' => 'required|string|max:500', 'country' => ['required', 'regex:/^[A-Z]{2}$/D'], 'region' => 'nullable|string|max:100', 'placement' => ['required', Rule::in(['discovery_banner', 'station_detail'])], 'budget_units' => 'required|integer|between:1,1000000', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'idempotency_key' => 'required|string|max:100']);
        foreach (['invoice_id', 'station_id', 'budget_units'] as $key) {
            $v[$key] = (int) $v[$key];
        }
        foreach (['starts_at', 'ends_at'] as $key) {
            $v[$key] = CarbonImmutable::parse($v[$key])->utc()->toDateTimeString();
        }
        $v['region'] = $v['region'] ?? null;

        return response()->json(['campaign' => $this->safe($s->create($r->user(), $v), $s)], 201);
    }

    public function cancelIntent(Request $r, CampaignService $s)
    {
        $v = $r->validate(['idempotency_key' => 'required|string|max:100']);
        $receipt = $s->cancelIntent($r->user(), $v['idempotency_key']);

        return response()->json(['cancelled' => $receipt['cancelled'], 'campaign' => $receipt['campaign'] ? $this->safe($receipt['campaign'], $s) : null]);
    }

    public function show(Request $r, int $campaign, CampaignService $s)
    {
        return DB::transaction(function () use ($r, $campaign, $s) {
            $row = $this->scope($r)->where('id', $campaign)->first() ?? abort(404);

            return response()->json([
                'campaign' => $this->safe($row, $s),
                'currently_eligible' => $s->eligible($row),
                'events' => DB::table('commercial_campaign_events')
                    ->where('campaign_id', $campaign)->orderBy('id')->get(),
                'deliveries' => DB::table('commercial_campaign_deliveries')
                    ->where('campaign_id', $campaign)->orderByDesc('id')->paginate(25),
            ])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function action(Request $r, int $campaign, string $action, CampaignService $s)
    {
        return DB::transaction(function () use ($r, $campaign, $action, $s) {
            $this->scope($r, 404)->where('id', $campaign)->first() ?? abort(404);
            $v = $r->validate(['reason' => 'required|string|min:5|max:500',
                'expected_control_revision' => 'sometimes|integer|min:1']);

            return response()->json(['campaign' => $this->safe(
                $s->action($campaign, $r->user(), $action, $v['reason'],
                    isset($v['expected_control_revision'])
                        ? (int) $v['expected_control_revision'] : null), $s
            )])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    /**
     * A sponsored placement page must contain eligible placements, not a
     * page of candidate campaigns with ineligible entries removed after
     * pagination. The old projection silently returned empty pages even when
     * valid sponsored stations existed on the next database page.
     *
     * Source eligibility is dynamic (credits, uncertain PSP status, paid
     * package entitlements, suspended vendors, delivery budget), so never
     * replace CampaignService::eligible with a stale approval-only SQL count.
     */
    public function eligible(Request $r, CampaignService $s)
    {
        $v = $r->validate([
            'country' => ['required_without:station_ids', 'regex:/^[A-Z]{2}$/D'],
            'placement' => ['required', Rule::in(['discovery_banner', 'station_detail'])],
            'region' => 'nullable|string|max:100',
            'station_id' => 'sometimes|integer|min:1|prohibits:station_ids',
            'station_ids' => ['sometimes', 'array', 'min:1', 'max:25',
                Rule::prohibitedIf($r->input('placement') !== 'discovery_banner')],
            'station_ids.*' => 'required|integer|min:1|distinct',
            'page' => 'sometimes|integer|between:1,100000',
            'per_page' => 'sometimes|integer|between:1,25',
        ]);
        $page = (int) ($v['page'] ?? 1);
        $size = (int) ($v['per_page'] ?? 25);
        if (! config('campaigns.enabled')) {
            // A disabled sponsorship adapter never publishes an ad, and
            // does not perform per-invoice finance lookups just to return
            // a false approved-candidate page.
            return response()->json([
                'placements' => [], 'current_page' => $page,
                'last_page' => 1, 'per_page' => $size, 'total' => 0,
                'ranking_basis' => 'SPONSORED_ID_ORDER_NOT_PERFORMANCE',
                'client_telemetry_billable' => false,
            ])->header('Cache-Control', 'private, no-store');
        }

        // Cheap relational exclusions first. Never turn a paid package
        // entitlement into a claim of real audience impressions, or assume
        // an APPROVED row is still entitled to a delivery after a refund.
        $candidates = DB::table('commercial_campaigns as c')
            ->when(isset($v['station_id']),
                fn ($q) => $q->where('c.station_id', (int) $v['station_id']))
            ->when(isset($v['station_ids']),
                fn ($q) => $q->whereIn('c.station_id', $v['station_ids']))
            ->when(isset($v['country']),
                fn ($q) => $q->where('c.country', $v['country']))
            ->where('c.status', 'approved')
            ->where('c.placement', $v['placement'])
            ->where('c.starts_at', '<=', now())
            ->where('c.ends_at', '>', now())
            ->where(fn ($q) => $q->whereNull('c.region')
                ->orWhere('c.region', $v['region'] ?? ''))
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('stations as st')
                ->whereColumn('st.id', 'c.station_id')
                ->whereColumn('st.vendor_id', 'c.vendor_id')
                ->whereColumn('st.country', 'c.country'))
            ->whereRaw('(SELECT COUNT(*) FROM commercial_campaign_deliveries AS d
                WHERE d.campaign_id = c.id AND d.kind = ?) < c.budget_units',
                ['delivery'])
            ->orderBy('c.id')
            ->limit(1001)
            ->get(['c.*']);

        // The exact eligibility contract requires checking live package
        // state for every candidate. A bounded failure is safer than lying
        // about last_page or silently skipping eligible later campaigns.
        abort_if($candidates->count() > 1000, 422,
            'Narrow the station catalog page or country before listing sponsored placements.');

        $valid = [];
        foreach ($candidates as $row) {
            if (! $s->eligible($row)) {
                continue;
            }
            $valid[] = [
                'campaign_id' => $row->id, 'station_id' => $row->station_id,
                'title' => $row->title, 'title_ar' => $row->title_ar,
                'description' => $row->description,
                'description_ar' => $row->description_ar,
                'sponsored' => true,
                'compatibility_verified' => false,
                'availability_verified' => false,
                'capability_override' => false,
            ];
        }

        $total = count($valid);
        $last = max(1, (int) ceil($total / $size));
        $placements = array_slice($valid, ($page - 1) * $size, $size);

        return response()->json([
            'placements' => $placements,
            'current_page' => $page,
            'last_page' => $last,
            'per_page' => $size,
            'total' => $total,
            'ranking_basis' => 'SPONSORED_ID_ORDER_NOT_PERFORMANCE',
            'client_telemetry_billable' => false,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function delivery(Request $r, CampaignService $s)
    {
        $secret = config('campaigns.delivery_secret');
        $timestamp = (string) $r->header('X-Campaign-Timestamp');
        $signature = (string) $r->header('X-Campaign-Signature');
        abort_unless(is_string($secret) && strlen($secret) >= 32, 503, 'Trusted delivery signing is not configured.');
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300 && hash_equals(hash_hmac('sha256', $timestamp.'.'.$r->getContent(), $secret), $signature), 401, 'Invalid or expired campaign delivery signature.');
        $v = $r->validate(['campaign_id' => 'required|integer|min:1', 'event_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/D'], 'kind' => 'required|in:delivery,click', 'parent_event_id' => 'required_if:kind,click|nullable|string|max:100', 'country' => ['required', 'regex:/^[A-Z]{2}$/D'], 'placement' => 'required|in:discovery_banner,station_detail', 'region' => 'nullable|string|max:100', 'occurred_at' => 'required|date']);
        $v['campaign_id'] = (int) $v['campaign_id'];
        $v['parent_event_id'] = $v['parent_event_id'] ?? null;
        $v['region'] = $v['region'] ?? null;
        $v['occurred_at'] = CarbonImmutable::parse($v['occurred_at'])->utc()->toDateTimeString();

        return response()->json($s->ingest($v));
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\Commercial\Campaigns\CampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CommercialCampaignController extends Controller
{
    private function scope(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['vendor', 'admin'], true), 403);

        return DB::table('commercial_campaigns')->when($r->user()->role !== 'admin', fn ($q) => $q->where('vendor_id', $r->user()->id));
    }

    private function safe(object $row, CampaignService $service): array
    {
        $data = (array) $row;
        unset($data['request_key'], $data['fingerprint']);

        return $data + ['delivered_units' => $service->delivered($row->id), 'verified_server_clicks' => DB::table('commercial_campaign_deliveries')->where('campaign_id', $row->id)->where('kind', 'click')->count(), 'unit' => 'PREPAID_PACKAGE_DELIVERY_UNIT', 'additional_cash_charge' => false, 'human_impressions_verified' => false, 'sponsored' => true];
    }

    public function index(Request $r, CampaignService $s)
    {
        return $this->scope($r)->orderByDesc('id')->paginate(25)->through(fn ($row) => $this->safe($row, $s));
    }

    public function stations(Request $r)
    {
        abort_unless($r->user()->role === 'vendor', 403);

        return DB::table('stations')->where('vendor_id', $r->user()->id)->orderBy('id')->select('id', 'name', 'country')->paginate(25);
    }

    public function packages(Request $r, CampaignService $s)
    {
        abort_unless(in_array($r->user()->role, ['vendor', 'admin'], true), 403);
        $q = DB::table('commercial_subscription_invoices as i')->join('commercial_subscriptions as s', 's.id', '=', 'i.subscription_id')->join('commercial_plan_versions as p', 'p.id', '=', 'i.plan_id')->where('p.audience', 'vendor')->when($r->user()->role !== 'admin', fn ($q) => $q->where('s.user_id', $r->user()->id))->select('i.*', 's.user_id');

        return $q->orderByDesc('i.id')->paginate(25)->through(function ($i) use ($s) {
            $eligible = true;
            try {
                $s->billing($i->id, $i->user_id);
            } catch (HttpException $error) {
                $eligible = false;
            }

            return ['invoice_id' => $i->id, 'vendor_id' => $i->user_id, 'number' => $i->number, 'currency' => $i->currency, 'minor_units' => $i->minor_units, 'total_minor' => $i->total_minor, 'period_start' => $i->period_start, 'period_end' => $i->period_end, 'campaign_eligible' => $eligible, 'entitlements' => json_decode($i->entitlements, true), 'evidence' => DB::table('commercial_subscription_receipts')->where('invoice_id', $i->id)->value('evidence') ?? 'UNPAID'];
        });
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

    public function show(Request $r, int $campaign, CampaignService $s)
    {
        $row = $this->scope($r)->where('id', $campaign)->first() ?? abort(404);

        return response()->json(['campaign' => $this->safe($row, $s), 'currently_eligible' => $s->eligible($row), 'events' => DB::table('commercial_campaign_events')->where('campaign_id', $campaign)->orderBy('id')->get(), 'deliveries' => DB::table('commercial_campaign_deliveries')->where('campaign_id', $campaign)->orderByDesc('id')->paginate(25)]);
    }

    public function action(Request $r, int $campaign, string $action, CampaignService $s)
    {
        $this->scope($r)->where('id', $campaign)->first() ?? abort(404);
        $v = $r->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json(['campaign' => $this->safe($s->action($campaign, $r->user(), $action, $v['reason']), $s)]);
    }

    public function eligible(Request $r, CampaignService $s)
    {
        $v = $r->validate(['country' => ['required', 'regex:/^[A-Z]{2}$/D'], 'placement' => ['required', Rule::in(['discovery_banner', 'station_detail'])], 'region' => 'nullable|string|max:100']);
        $rows = DB::table('commercial_campaigns')->where('status', 'approved')->where('country', $v['country'])->where('placement', $v['placement'])->where('starts_at', '<=', now())->where('ends_at', '>', now())->where(fn ($q) => $q->whereNull('region')->orWhere('region', $v['region'] ?? ''))->orderBy('id')->paginate(25)->through(function ($row) use ($s) {
            if (! $s->eligible($row)) {
                return null;
            }

            return ['campaign_id' => $row->id, 'station_id' => $row->station_id, 'title' => $row->title, 'title_ar' => $row->title_ar, 'description' => $row->description, 'description_ar' => $row->description_ar, 'sponsored' => true, 'compatibility_verified' => false, 'availability_verified' => false, 'capability_override' => false];
        });

        // Filtered nulls never become placements; clients must not treat sponsored
        // selection as charger discovery, compatibility, live state or routing proof.
        return response()->json(['placements' => array_values(array_filter($rows->items())), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'ranking_basis' => 'SPONSORED_ID_ORDER_NOT_PERFORMANCE', 'client_telemetry_billable' => false]);
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

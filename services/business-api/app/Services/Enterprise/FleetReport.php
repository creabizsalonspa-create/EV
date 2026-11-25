<?php

namespace App\Services\Enterprise;

use App\Models\Fleet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FleetReport
{
    /** Only claims deliberately shared with this fleet are read; never personal session history. */
    public function read(Fleet $fleet, array $filters): array
    {
        $timezone = DB::table('regions')->where('country', $fleet->country)->value('timezone');
        abort_unless(is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true), 409, 'Fleet timezone unavailable.');
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], $timezone);
        $until = CarbonImmutable::createFromFormat('!Y-m-d', $filters['through'], $timezone)->addDay();
        abort_unless($from < $until && $from->diffInDays($until) <= 366, 422, 'Choose at most 366 local dates.');
        $center = $filters['cost_center_id'] ?? null;
        if ($center !== null) {
            abort_unless(DB::table('cost_centers')->where('fleet_id', $fleet->id)->where('id', $center)->exists(), 404);
        }
        $claims = DB::table('corporate_claims as c')
            ->join('corporate_agreements as a', 'a.id', '=', 'c.agreement_id')
            ->join('corporate_claim_decisions as d', 'd.claim_id', '=', 'c.id')
            ->where('a.fleet_id', $fleet->id)->where('d.decision', 'approved')
            ->where('c.created_at', '>=', $from->utc())->where('c.created_at', '<', $until->utc())
            ->when($center !== null, fn ($q) => $q->where('c.cost_center_id', $center))
            ->orderBy('c.id')->limit(2501)
            ->get(['c.cost_center_id', 'c.cost_center_code', 'c.cost_center_name', 'c.currency', 'c.minor_units', 'c.amount_minor']);
        abort_if($claims->count() > 2500, 422, 'Report exceeds 2500 claims. Select a shorter period or cost center.');
        $groups = [];
        foreach ($claims as $claim) {
            // Reject malformed database values before string/integer conversion.
            abort_unless((is_int($claim->cost_center_id) || is_string($claim->cost_center_id))
                && preg_match('/\A[1-9][0-9]{0,15}\z/', (string) $claim->cost_center_id) === 1
                && (int) $claim->cost_center_id <= 9007199254740991
                && (is_int($claim->minor_units) || is_string($claim->minor_units))
                && preg_match('/\A[0-3]\z/', (string) $claim->minor_units) === 1
                && (is_int($claim->amount_minor) || is_string($claim->amount_minor))
                && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', (string) $claim->amount_minor) === 1
                && is_string($claim->currency) && preg_match('/\A[A-Z]{3}\z/', $claim->currency) === 1
                && is_string($claim->cost_center_code) && is_string($claim->cost_center_name),
                409, 'Invalid recorded fleet claim source.');
            // Historic cost-center snapshots and precision form separate groups.
            $key = json_encode([$claim->cost_center_id, $claim->cost_center_code, $claim->cost_center_name, $claim->currency, $claim->minor_units], JSON_THROW_ON_ERROR);
            $groups[$key] ??= ['cost_center_id' => (int) $claim->cost_center_id, 'code' => $claim->cost_center_code, 'name' => $claim->cost_center_name, 'currency' => $claim->currency, 'minor_units' => (int) $claim->minor_units, 'claims' => 0, 'amount_minor' => '0'];
            $groups[$key]['claims']++;
            $groups[$key]['amount_minor'] = $this->add($groups[$key]['amount_minor'], (string) $claim->amount_minor);
        }

        return ['as_of' => now()->toIso8601String(), 'fleet' => ['id' => $fleet->id, 'name' => $fleet->name, 'country' => $fleet->country],
            'period' => ['from' => $filters['from'], 'through' => $filters['through'], 'timezone' => $timezone, 'from_utc' => $from->utc()->toIso8601String(), 'until_utc_exclusive' => $until->utc()->toIso8601String(), 'cost_center_id' => $center === null ? null : (int) $center],
            'current_inventory' => ['active_members' => DB::table('fleet_members')->where('fleet_id', $fleet->id)->where('status', 'active')->count(), 'invited_members' => DB::table('fleet_members')->where('fleet_id', $fleet->id)->where('status', 'invited')->count(), 'assigned_vehicles' => DB::table('fleet_vehicles')->where('fleet_id', $fleet->id)->count(), 'cost_centers' => DB::table('cost_centers')->where('fleet_id', $fleet->id)->count()],
            'basis' => 'approved_claims_submitted_in_period', 'approved_claims' => $claims->count(), 'groups' => array_values($groups), 'physical_charging_verified' => false];
    }

    private function add(string $left, string $right): string
    {
        abort_unless(preg_match('/^\d+$/D', $left) && preg_match('/^\d+$/D', $right), 409, 'Invalid recorded claim amount.');
        $a = strrev($left);
        $b = strrev($right);
        $carry = 0;
        $out = '';
        for ($i = 0; $i < max(strlen($a), strlen($b)); $i++) {
            $sum = (int) ($a[$i] ?? '0') + (int) ($b[$i] ?? '0') + $carry;
            $out .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }
        if ($carry) {
            $out .= (string) $carry;
        }

        return ltrim(strrev($out), '0') ?: '0';
    }
}

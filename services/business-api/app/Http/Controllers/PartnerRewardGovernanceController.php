<?php

namespace App\Http\Controllers;

use App\Services\Retail\PartnerRewardGovernance;
use Illuminate\Http\Request;

final class PartnerRewardGovernanceController extends Controller
{
    public function index(Request $request, int $partner, PartnerRewardGovernance $governance)
    {
        $request->validate(['page' => 'sometimes|integer|between:1,100000']);

        return response()->json($governance->index($request->user(), $partner))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $partner, int $review, PartnerRewardGovernance $governance)
    {
        $request->validate(['page' => 'sometimes|integer|between:1,100000']);

        return response()->json($governance->history($request->user(), $partner, $review))->header('Cache-Control', 'private, no-store');
    }

    public function propose(Request $request, int $partner, PartnerRewardGovernance $governance)
    {
        return $this->write($request, $partner, null, 'propose', $governance);
    }

    public function decide(Request $request, int $partner, int $review, string $decision, PartnerRewardGovernance $governance)
    {
        return $this->write($request, $partner, $review, $decision, $governance);
    }

    private function write(Request $request, int $partner, ?int $review, string $decision, PartnerRewardGovernance $governance)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $values = $request->validate([
            'reason' => 'required|string|min:5|max:500',
            'idempotency_key' => 'required|uuid',
        ] + ($review === null ? [
            'expected_partner_revision' => 'required|integer|min:1',
            'reward_id' => 'required|integer|min:1',
        ] : ['expected_revision' => 'required|integer|min:1']));
        foreach (array_intersect(['expected_partner_revision', 'reward_id', 'expected_revision'], array_keys($values)) as $field) {
            $values[$field] = (int) $values[$field];
        }

        return response()->json(['review' => $governance->record($request->user(), $partner, $review, $decision, $values)], $review === null ? 201 : 200)
            ->header('Cache-Control', 'private, no-store');
    }
}

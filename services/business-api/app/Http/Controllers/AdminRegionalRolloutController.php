<?php

namespace App\Http\Controllers;

use App\Security\AdminTransactionAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AdminRegionalRolloutController extends Controller
{
    private const DOMAINS = ['legal', 'payments', 'tax', 'telecom', 'charging_partners'];

    public function show(Request $request, string $country): JsonResponse
    {
        return DB::transaction(function () use ($request, $country) {
            AdminTransactionAuthority::requireCurrent($request->user());
            $region = $this->region($country);

            return response()->json($this->report($region))->header('Cache-Control', 'private, no-store');
        });
    }

    public function record(Request $request, string $country): JsonResponse
    {
        return DB::transaction(function () use ($request, $country) {
            $actor = AdminTransactionAuthority::requireCurrent($request->user());
            $values = $request->validate([
                'configuration_revision' => 'required|integer|min:1|max:9007199254740991',
                'domain' => ['required', Rule::in(self::DOMAINS)],
                'decision' => ['required', Rule::in(['reviewed', 'blocked'])],
                'evidence_reference' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D'],
                'expected_review_id' => 'present|nullable|integer|min:1|max:9007199254740991',
            ]);

            $region = $this->region($country);
            abort_unless((int) $region->configuration_revision === (int) $values['configuration_revision'],
                409, 'Regional configuration changed. Refresh before recording a review.');

            $latest = DB::table('regional_rollout_reviews')
                ->where('country', $country)
                ->where('configuration_revision', $region->configuration_revision)
                ->where('domain', $values['domain'])
                ->orderByDesc('id')->first();
            $latestId = $latest ? (int) $latest->id : null;
            $expectedId = $values['expected_review_id'] === null ? null : (int) $values['expected_review_id'];
            if ($latest && $latest->decision === $values['decision']
                && $latest->evidence_reference === $values['evidence_reference']) {
                return response()->json($this->report($region))->header('Cache-Control', 'private, no-store');
            }

            abort_unless($latestId === $expectedId, 409, 'The review changed. Refresh before recording a new decision.');
            $id = DB::table('regional_rollout_reviews')->insertGetId([
                'country' => $country,
                'configuration_revision' => $region->configuration_revision,
                'domain' => $values['domain'],
                'decision' => $values['decision'],
                'evidence_reference' => $values['evidence_reference'],
                'reviewer_id' => $actor->id,
                'recorded_at' => now('UTC'),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id,
                'action' => 'region.rollout_review_recorded',
                'subject' => $country.':'.$id,
                'created_at' => now('UTC'),
            ]);

            return response()->json($this->report($region), 201)->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    private function region(string $country): object
    {
        return DB::table('regions')->where('country', $country)->lockForUpdate()->firstOrFail();
    }

    private function report(object $region): array
    {
        $latestIds = DB::table('regional_rollout_reviews')
            ->where('country', $region->country)
            ->where('configuration_revision', $region->configuration_revision)
            ->groupBy('domain')->selectRaw('MAX(id)');
        $reviews = DB::table('regional_rollout_reviews')
            ->whereIn('id', $latestIds)
            ->get(['id', 'domain', 'decision', 'evidence_reference', 'recorded_at'])
            ->keyBy('domain');

        return [
            'country' => $region->country,
            'configuration_revision' => (int) $region->configuration_revision,
            'checks' => array_map(function (string $domain) use ($reviews) {
                $review = $reviews->get($domain);

                return [
                    'domain' => $domain,
                    'review_id' => $review ? (int) $review->id : null,
                    'decision' => $review?->decision ?? 'pending',
                    'evidence_reference' => $review?->evidence_reference,
                    'recorded_at' => $review?->recorded_at,
                ];
            }, self::DOMAINS),
            'external_evidence_verified' => false,
            'rollout_authorized' => false,
        ];
    }
}
